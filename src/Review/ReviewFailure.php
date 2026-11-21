<?php

declare(strict_types=1);

namespace App\Review;

use Mcp\Client\Exception\AuthorizationException;
use Mcp\Client\Exception\ConnectionException;
use Mcp\Client\Exception\TimeoutException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

final class ReviewFailure extends \RuntimeException
{
    public function __construct(public readonly string $stage, public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(self::message($stage, $reason), 0, $previous);
    }

    public static function wrap(string $stage, \Throwable $error): self
    {
        if ($error instanceof self) {
            return $error;
        }
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AuthorizationException) {
                return new self($stage, 'mcp_authentication', $error);
            }
            if ($cause instanceof AuthenticationException) {
                return new self($stage, 'credentials', $error);
            }
            if ($cause instanceof RateLimitExceededException) {
                return new self($stage, 'quota', $error);
            }
            if ($cause instanceof TimeoutException || $cause instanceof TimeoutExceptionInterface) {
                return new self($stage, 'timeout', $error);
            }
        }
        $reason = match (true) {
            $error instanceof ConnectionException => 'connection',
            $error instanceof \UnexpectedValueException, $error instanceof \JsonException => 'invalid_response',
            default => 'provider',
        };

        return new self($stage, $reason, $error);
    }

    public static function fromToolMessage(string $message): self
    {
        $reason = match (true) {
            preg_match('/quota|rate.limit|too many requests|\b429\b/i', $message) === 1 => 'quota',
            preg_match('/invalid.*(?:credentials|api.?key)|unauthori[sz]ed|missing.*api.?key|\b401\b/i', $message) === 1 => 'credentials',
            preg_match('/timed?.?out|timeout/i', $message) === 1 => 'timeout',
            default => 'provider',
        };

        return new self('capture', $reason);
    }

    private static function message(string $stage, string $reason): string
    {
        $service = $stage === 'capture' ? 'Snapshot Site' : 'OpenAI';
        $step = match ($stage) {
            'capture' => 'La capture des pages',
            'inspector' => 'La première analyse',
            'critic' => 'La seconde analyse',
            default => 'La préparation de la comparaison',
        };

        return match ($reason) {
            'mcp_authentication' => 'Le serveur MCP hébergé refuse cette authentification. Utilisez le mode local (SNAPSHOT_SITE_MCP_TRANSPORT=stdio) pour une connexion par clé API.',
            'credentials' => $service.' a refusé la clé API. Vérifiez '.($stage === 'capture' ? 'SNAPSHOT_SITE_API_KEY' : 'OPENAI_API_KEY').' dans .env.local et utilisez une clé valide de ce service.',
            'quota' => 'Le quota ou la limite de requêtes '.$service.' est atteint. Vérifiez les crédits disponibles, puis réessayez.',
            'timeout' => $step.' a dépassé le délai autorisé. Réessayez dans un instant.',
            'connection' => 'La connexion à Snapshot Site a échoué. Vérifiez votre accès réseau et la disponibilité du service.',
            'invalid_response' => $step.' a renvoyé un résultat incomplet ou illisible. Aucun verdict ne peut être établi.',
            'setup' => 'Le serveur MCP local est absent. Exécutez npm ci dans le dossier du projet avant de comparer vos pages.',
            default => $step.' a échoué. Aucun verdict n’a été produit ; réessayez dans un instant.',
        };
    }
}
