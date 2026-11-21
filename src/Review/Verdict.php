<?php

declare(strict_types=1);

namespace App\Review;

enum Verdict: string
{
    case Confirmed = 'confirmed';
    case Contradicted = 'contradicted';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmé',
            self::Contradicted => 'Contredit',
            self::Uncertain => 'Indéterminé',
        };
    }
}
