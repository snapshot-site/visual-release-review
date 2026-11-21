<?php

declare(strict_types=1);

namespace App\Review;

final class PublicUrl
{
    public function __construct(private readonly ?\Closure $resolver = null)
    {
    }

    public function validate(string $url): string
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || strlen($url) > 2048) {
            throw new \InvalidArgumentException('Utilisez une URL publique HTTP ou HTTPS, sans identifiants ni fragment.');
        }
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new \InvalidArgumentException('Les pages doivent être accessibles sur le port 80 ou 443.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } elseif ($this->resolver) {
            $addresses = ($this->resolver)($host);
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = array_map(static fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? '', $records);
        }
        if (!$addresses) {
            throw new \InvalidArgumentException('Cette adresse ne correspond pas à un site public accessible.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || str_starts_with(strtolower($address), '::ffff:')) {
                throw new \InvalidArgumentException('Les adresses locales, privées ou réservées ne sont pas acceptées.');
            }
        }

        return $url;
    }
}
