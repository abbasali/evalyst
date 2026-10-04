<?php

use App\Enums\AssessmentStatus;
use App\Enums\LateOverride;
use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Grading\LatePenaltyCalculator;
use App\Models\Assessment;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Tests\TestCase;

// Models need the app (casts use the connection grammar), but never touch the database.
uses(TestCase::class);

const DEADLINE = '2026-10-10 18:00:00';

function calculator(array $assessment = [], array $participant = []): LatePenaltyCalculator
{
    $model = new Assessment;
    $model->forceFill([
        'status' => AssessmentStatus::Published,
        'opens_at' => CarbonImmutable::parse('2026-10-01 00:00:00'),
        'closes_at' => CarbonImmutable::parse(DEADLINE),
        'grace_minutes' => 0,
        'late_policy' => LatePolicy::Penalty,
        'penalty_type' => PenaltyType::PerDay,
        'penalty_value' => 2,
        ...$assessment,
    ]);

    $person = new Participant;
    $person->forceFill(['penalty_waived' => false, ...$participant]);

    return LatePenaltyCalculator::for($model, $person);
}

function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time);
}

dataset('submit decisions', [
    'draft' => [['status' => AssessmentStatus::Draft], [], '2026-10-05 10:00', false, false],
    'before opening' => [[], [], '2026-09-30 10:00', false, false],
    'on time' => [[], [], '2026-10-10 17:59', true, false],
    'exactly at deadline' => [[], [], DEADLINE, true, false],
    'blocked after deadline' => [[], ['late_override' => LateOverride::Block], '2026-10-10 18:01', false, false],
    'blocked before deadline' => [[], ['late_override' => LateOverride::Block], '2026-10-10 17:00', true, false],
    'allowed despite policy' => [['late_policy' => LatePolicy::NotAllowed], ['late_override' => LateOverride::Allow], '2026-10-20 10:00', true, true],
    'within grace' => [['grace_minutes' => 15], [], '2026-10-10 18:10', true, false],
    'not allowed after deadline' => [['late_policy' => LatePolicy::NotAllowed], [], '2026-10-10 18:01', false, false],
    'past hard cutoff' => [['hard_cutoff_at' => at('2026-10-12 18:00')], [], '2026-10-12 18:01', false, false],
    'late before cutoff' => [['hard_cutoff_at' => at('2026-10-12 18:00')], [], '2026-10-11 10:00', true, true],
    'deadline override' => [[], ['deadline_override_at' => at('2026-10-15 18:00')], '2026-10-14 10:00', true, false],
]);

test('who can submit when', function (array $assessment, array $participant, string $now, bool $allowed, bool $late) {
    $decision = calculator($assessment, $participant)->canSubmit(at($now));

    expect($decision->allowed)->toBe($allowed);

    if ($allowed) {
        expect($decision->late)->toBe($late);
    } else {
        expect($decision->reason)->not->toBeEmpty();
    }
})->with('submit decisions');

dataset('minutes late', [
    'exactly at deadline' => [[], DEADLINE, 0],
    'one second late' => [[], '2026-10-10 18:00:01', 1],
    'inside grace' => [['grace_minutes' => 10], '2026-10-10 18:10:00', 0],
    'one minute after grace' => [['grace_minutes' => 10], '2026-10-10 18:11:00', 1],
    'a day late' => [[], '2026-10-11 18:00:00', 1440],
]);

test('minutes late counts from the deadline plus grace', function (array $assessment, string $submittedAt, int $expected) {
    expect(calculator($assessment)->minutesLate(at($submittedAt)))->toBe($expected);
})->with('minutes late');

dataset('penalties', [
    'on time' => [[], [], 0, 0.0],
    'fixed' => [['penalty_type' => PenaltyType::Fixed, 'penalty_value' => 3], [], 5000, 3.0],
    'per hour, started hour' => [['penalty_type' => PenaltyType::PerHour, 'penalty_value' => 0.5], [], 61, 1.0],
    'per day, one minute' => [[], [], 1, 2.0],
    'per day, 1 day 1 minute' => [[], [], 1441, 4.0],
    'cap reached' => [['penalty_cap' => 5], [], 1440 * 10, 5.0],
    'no penalty policy' => [['late_policy' => LatePolicy::Allowed], [], 3000, 0.0],
    'waived' => [[], ['penalty_waived' => true], 3000, 0.0],
    'override' => [[], ['penalty_override' => 1.5], 3000, 1.5],
    'waived beats override' => [[], ['penalty_waived' => true, 'penalty_override' => 4], 3000, 0.0],
    'override ignored when on time' => [[], ['penalty_override' => 1], 0, 0.0],
    'override with no-penalty policy' => [['late_policy' => LatePolicy::Allowed], ['penalty_override' => 1], 30, 1.0],
]);

test('late penalties', function (array $assessment, array $participant, int $minutes, float $expected) {
    expect(calculator($assessment, $participant)->penalty($minutes))->toBe($expected);
})->with('penalties');

test('the final score never goes below zero', function () {
    expect(LatePenaltyCalculator::finalScore(8, 2.5))->toBe(5.5)
        ->and(LatePenaltyCalculator::finalScore(1, 3))->toBe(0.0);
});
