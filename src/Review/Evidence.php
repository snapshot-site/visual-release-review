<?php

declare(strict_types=1);

namespace App\Review;

final readonly class Evidence
{
    public function __construct(public string $before, public string $after, public ?string $diff = null, public ?float $mismatch = null)
    {
    }
}
