<?php

namespace App\Services\GitHub\Checks;

use App\Data\RepoSnapshot;
use App\Http\Requests\Instructor\AssignmentRulesRequest;
use App\Models\Assessment;
use App\Services\GitHub\PathFilter;

/**
 * At least one included file matching `glob` contains `pattern`. Pass/fail.
 */
class FileContains implements Check
{
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult
    {
        $glob = (string) ($config['glob'] ?? '');
        $regex = AssignmentRulesRequest::delimit((string) ($config['pattern'] ?? ''));
        $candidates = 0;
        $found = [];

        foreach ($snapshot->includedFiles as $path => $content) {
            if (! PathFilter::matches($glob, $path)) {
                continue;
            }

            $candidates++;

            if (@preg_match($regex, $content) === 1) {
                $found[] = $path;
            }
        }

        $passed = $found !== [];

        return new CheckResult(
            passed: $passed,
            score: $passed ? $marks : 0.0,
            reasoning: $passed
                ? __('Found in :count of :total matching file(s).', ['count' => count($found), 'total' => $candidates])
                : __('Not found in :total file(s) matching :glob.', ['total' => $candidates, 'glob' => $glob]),
            evidence: array_slice($found, 0, 10),
        );
    }
}
