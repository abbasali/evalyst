<?php

namespace App\Grading;

final readonly class SubmitDecision
{
    /**
     * @param  string|null  $reason  Why submitting is blocked, shown to the student.
     */
    public function __construct(
        public bool $allowed,
        public bool $late = false,
        public ?string $reason = null,
    ) {}
}
