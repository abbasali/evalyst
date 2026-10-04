<?php

use App\Models\Assessment;
use App\Models\Team;
use App\Services\GitHub\Checks\CommitMessagePattern;
use App\Services\GitHub\Checks\FileContains;
use App\Services\GitHub\Checks\MinCommitDays;
use App\Services\GitHub\Checks\MinCommits;
use App\Services\GitHub\Checks\PathAbsent;
use App\Services\GitHub\Checks\PathExists;
use Tests\Support\SnapshotBuilder;
use Tests\TestCase;

uses(TestCase::class);

function checkAssessment(): Assessment
{
    $assessment = new Assessment;
    $assessment->setRelation('team', (new Team)->forceFill(['timezone' => 'Asia/Kolkata']));

    return $assessment;
}

test('min commits gives partial credit and ignores merges', function () {
    $snapshot = SnapshotBuilder::make()->commits(9)->commit('Merge branch x', parents: 2)->build();

    $result = (new MinCommits)->check($snapshot, ['min' => 15], checkAssessment(), 5);

    expect($result->passed)->toBeFalse()->and($result->score)->toBe(3.0);
    expect((new MinCommits)->check($snapshot, ['min' => 9], checkAssessment(), 5)->score)->toBe(5.0);
});

test('commit days are counted in the course timezone', function () {
    // 20:00 UTC on the 1st is already the 2nd in India.
    $snapshot = SnapshotBuilder::make()
        ->commit('a', '2026-10-01 10:00:00')
        ->commit('b', '2026-10-01 20:00:00')
        ->commit('c', '2026-10-02 05:00:00')
        ->build();

    $result = (new MinCommitDays)->check($snapshot, ['min' => 3], checkAssessment(), 3);

    expect($result->evidence)->toBe(['2026-10-01', '2026-10-02'])->and($result->score)->toBe(2.0);
});

test('commit message pattern credits the matching share', function () {
    $snapshot = SnapshotBuilder::make()->commits(6)->commit('wip')->commit('stuff')->build();

    $result = (new CommitMessagePattern)->check($snapshot, ['pattern' => '^feat: ', 'min_ratio' => 1], checkAssessment(), 4);

    expect($result->passed)->toBeFalse()->and($result->score)->toBe(3.0)->and($result->evidence)->toHaveCount(2);
});

test('path checks use every path in the tree', function () {
    $snapshot = SnapshotBuilder::make()->paths('vendor/autoload.php', 'tests/Feature/PostTest.php', 'tests/Feature/UserTest.php')->build();

    expect((new PathAbsent)->check($snapshot, ['glob' => 'vendor/**'], checkAssessment(), 2)->passed)->toBeFalse()
        ->and((new PathAbsent)->check($snapshot, ['glob' => '.env'], checkAssessment(), 2)->score)->toBe(2.0)
        ->and((new PathExists)->check($snapshot, ['glob' => 'tests/Feature/*Test.php', 'min_matches' => 2], checkAssessment(), 2)->passed)->toBeTrue()
        ->and((new PathExists)->check($snapshot, ['glob' => 'tests/Feature/*Test.php', 'min_matches' => 3], checkAssessment(), 2)->score)->toBe(0.0);
});

test('file contains searches included files', function () {
    $snapshot = SnapshotBuilder::make()
        ->file('app/Http/Requests/StorePostRequest.php', '<?php public function rules(): array {}')
        ->file('app/Http/Controllers/PostController.php', '<?php // no validation')
        ->build();

    expect((new FileContains)->check($snapshot, ['glob' => 'app/Http/Requests/*.php', 'pattern' => 'function rules'], checkAssessment(), 3)->evidence)
        ->toBe(['app/Http/Requests/StorePostRequest.php'])
        ->and((new FileContains)->check($snapshot, ['glob' => 'app/Http/Controllers/*.php', 'pattern' => 'validate\('], checkAssessment(), 3)->passed)
        ->toBeFalse();
});
