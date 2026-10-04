<?php

namespace App\Data;

final readonly class GitHubRepository
{
    public function __construct(
        public string $owner,
        public string $name,
        public bool $private,
        public string $defaultBranch,
        public int $sizeKb,
        public bool $archived,
    ) {}
}
