<?php

namespace App\Grading;

final readonly class GateDecision
{
    /**
     * @param  list<string>  $reasons  Why the item needs review, e.g. ["low_confidence", "flag:off_topic"].
     */
    public function __construct(
        public bool $publish,
        public array $reasons = [],
    ) {}
}
