<?php

declare(strict_types=1);

namespace App\Review;

interface VisualReviewer
{
    public function review(ReviewInput $input, Evidence $evidence, ?Finding $inspection = null): Finding;
}
