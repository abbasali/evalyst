<?php

namespace App\Services\GitHub\Checks;

final readonly class CheckResult
{
    /**
     * @param  list<string>  $evidence  Paths or short SHAs.
     */
    public function __construct(
        public bool $passed,
        public float $score,
        public string $reasoning,
        public array $evidence = [],
    ) {}
}
