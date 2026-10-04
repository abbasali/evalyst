<?php

namespace App\Services\GitHub\Checks;

use App\Data\GitHubCommit;
use App\Data\RepoSnapshot;
use App\Http\Requests\Instructor\AssignmentRulesRequest;
use App\Models\Assessment;

/**
 * The share of non-merge commit messages matching `pattern` must reach `min_ratio`.
 * Partial credit is ratio / min_ratio, capped at 1.
 */
class CommitMessagePattern implements Check
{
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult
    {
        $regex = AssignmentRulesRequest::delimit((string) ($config['pattern'] ?? ''));
        $minRatio = min(1, max(0.01, (float) ($config['min_ratio'] ?? 1)));
        $commits = $snapshot->nonMergeCommits();

        if ($commits === []) {
            return new CheckResult(false, 0.0, __('No commits to check.'));
        }

        $failing = array_values(array_filter($commits, fn (GitHubCommit $commit) => @preg_match($regex, $commit->message) !== 1));
        $ratio = (count($commits) - count($failing)) / count($commits);

        return new CheckResult(
            passed: $ratio >= $minRatio,
            score: Partial::score($marks, $ratio / $minRatio),
            reasoning: __(':percent% of commit messages match (:required% required).', [
                'percent' => (int) round($ratio * 100),
                'required' => (int) round($minRatio * 100),
            ]),
            evidence: array_map(fn (GitHubCommit $commit) => substr($commit->sha, 0, 7).' '.$commit->message, array_slice($failing, 0, 5)),
        );
    }
}
