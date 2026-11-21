<?php

declare(strict_types=1);

namespace App\Review;

final readonly class ReviewRunner
{
    public function __construct(private ComparisonGateway $captures, private VisualReviewer $reviewer)
    {
    }

    public function run(ReviewInput $input): ReviewReport
    {
        try {
            $evidence = $this->captures->compare($input);
        } catch (\Throwable $error) {
            throw ReviewFailure::wrap('capture', $error);
        }
        try {
            $inspection = $this->reviewer->review($input, $evidence);
        } catch (\Throwable $error) {
            throw ReviewFailure::wrap('inspector', $error);
        }
        try {
            $critique = $this->reviewer->review($input, $evidence, $inspection);
        } catch (\Throwable $error) {
            throw ReviewFailure::wrap('critic', $error);
        }

        return new ReviewReport($input, $evidence, $inspection, $critique);
    }
}
