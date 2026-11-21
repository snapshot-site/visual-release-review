<?php

declare(strict_types=1);

namespace App\Review;

/**
 * @phpstan-type CaptureArguments array{url: string, width: int, height: int, fullSize: bool, hideCookie: bool}
 */
final readonly class ReviewInput
{
    public const WIDTH = 390;
    public const HEIGHT = 844;

    public function __construct(public string $before, public string $after, public string $claim, public string $locale = 'fr')
    {
        if (!in_array($locale, ['fr', 'en'], true)) {
            throw new \InvalidArgumentException('La langue choisie doit être fr ou en.');
        }
        if (mb_strlen(trim($claim)) < 10 || mb_strlen($claim) > 300) {
            throw new \InvalidArgumentException('Décrivez ce que vous voulez vérifier en 10 à 300 caractères.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, PublicUrl $urls, string $locale = 'fr'): self
    {
        foreach (['before', 'after', 'claim'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new \InvalidArgumentException('Renseignez les deux URL et le point à vérifier.');
            }
        }

        return new self($urls->validate(trim($data['before'])), $urls->validate(trim($data['after'])), trim($data['claim']), $locale);
    }

    /** @return array{before: CaptureArguments, after: CaptureArguments, threshold: float} */
    public function comparisonArguments(): array
    {
        $viewport = ['width' => self::WIDTH, 'height' => self::HEIGHT, 'fullSize' => false, 'hideCookie' => true];

        return ['before' => ['url' => $this->before] + $viewport, 'after' => ['url' => $this->after] + $viewport, 'threshold' => 0.1];
    }
}
