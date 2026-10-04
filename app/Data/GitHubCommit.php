<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class GitHubCommit
{
    public function __construct(
        public string $sha,
        public ?CarbonImmutable $date,
        public string $author = '',
        public string $message = '',
        public int $parents = 1,
    ) {}

    public function isMerge(): bool
    {
        return $this->parents > 1;
    }
}
