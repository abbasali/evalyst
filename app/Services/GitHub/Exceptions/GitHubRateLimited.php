<?php

namespace App\Services\GitHub\Exceptions;

use Carbon\CarbonImmutable;

class GitHubRateLimited extends GitHubUnavailable
{
    public function __construct(public readonly CarbonImmutable $resetsAt)
    {
        parent::__construct("GitHub rate limit reached; resets at {$resetsAt->toIso8601String()}.");
    }
}
