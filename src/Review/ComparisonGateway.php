<?php

declare(strict_types=1);

namespace App\Review;

interface ComparisonGateway
{
    public function compare(ReviewInput $input): Evidence;
}
