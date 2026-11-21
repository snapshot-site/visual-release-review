<?php

declare(strict_types=1);

namespace App\Review;

final readonly class Finding
{
    public function __construct(public Verdict $verdict, public string $observation, public string $evidence, public string $limitation)
    {
        foreach ([$observation, $limitation] as $text) {
            if (trim($text) === '' || mb_strlen($text) > 1500) {
                throw new \UnexpectedValueException('Le compte rendu du modèle est incomplet.');
            }
        }
        if (!in_array($evidence, ['before', 'after', 'both'], true)) {
            throw new \UnexpectedValueException('Le modèle ne cite pas une capture valide.');
        }
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Le modèle doit renvoyer un objet JSON.');
        }
        foreach (['verdict', 'observation', 'evidence', 'limitation'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new \UnexpectedValueException('Le compte rendu du modèle est incomplet.');
            }
        }
        $verdict = Verdict::tryFrom($data['verdict']);
        if (!$verdict) {
            throw new \UnexpectedValueException('Le modèle a renvoyé un verdict inconnu.');
        }

        return new self($verdict, $data['observation'], $data['evidence'], $data['limitation']);
    }
}
