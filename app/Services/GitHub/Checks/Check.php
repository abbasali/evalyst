<?php

namespace App\Services\GitHub\Checks;

use App\Data\RepoSnapshot;
use App\Models\Assessment;

interface Check
{
    /**
     * @param  array<string, mixed>  $config  The rule's validated config.
     */
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult;
}
