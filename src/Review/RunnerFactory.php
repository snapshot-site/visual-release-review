<?php

declare(strict_types=1);

namespace App\Review;

interface RunnerFactory
{
    public function create(): ReviewRunner;
}
