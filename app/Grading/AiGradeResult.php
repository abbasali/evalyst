<?php

namespace App\Grading;

/**
 * A validated AI grade for one open answer (see OpenAnswerResultValidator).
 */
final readonly class AiGradeResult
{
    /**
     * @param  list<array{criterion: string, awarded: float, max: float, note: string}>  $breakdown
     * @param  list<string>  $flags
     */
    public function __construct(
        public float $score,
        public string $feedback,
        public array $breakdown,
        public float $confidence,
        public array $flags,
    ) {}
}
