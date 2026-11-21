<?php

declare(strict_types=1);

namespace App\Review;

final readonly class ReviewReport
{
    public Verdict $verdict;
    public bool $disagreement;

    public function __construct(public ReviewInput $input, public Evidence $evidence, public Finding $inspector, public Finding $critic, public bool $replay = false)
    {
        $this->disagreement = $inspector->verdict !== $critic->verdict;
        // A disagreement stays visible instead of becoming a confident answer.
        $this->verdict = $this->disagreement ? Verdict::Uncertain : $critic->verdict;
    }
}
