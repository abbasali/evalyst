<?php

namespace App\Services\GitHub\Checks;

use App\Data\GitHubCommit;
use App\Data\RepoSnapshot;
use App\Models\Assessment;

/**
 * At least `min` non-merge commits, with linear partial credit.
 */
class MinCommits implements Check
{
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult
    {
        $min = max(1, (int) ($config['min'] ?? 1));
        $commits = $snapshot->nonMergeCommits();
        $count = count($commits);

        return new CheckResult(
            passed: $count >= $min,
            score: Partial::score($marks, $count / $min),
            reasoning: __(':count of :min required commits (merge commits not counted).', ['count' => $count, 'min' => $min]),
            evidence: array_map(fn (GitHubCommit $commit) => substr($commit->sha, 0, 7), array_slice($commits, 0, 10)),
        );
    }
}
