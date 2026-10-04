<?php

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\Question;
use App\Support\AccessCode;
use Illuminate\Support\Carbon;

dataset('time states', [
    'draft, inside window' => [AssessmentStatus::Draft, '-1 hour', '+1 hour', 'draft', false, false, false],
    'published, no opens_at' => [AssessmentStatus::Published, null, '+1 hour', 'open', true, false, false],
    'published, before opens_at' => [AssessmentStatus::Published, '+1 hour', '+2 hours', 'upcoming', false, true, false],
    'published, exactly at opens_at' => [AssessmentStatus::Published, '+0 seconds', '+1 hour', 'open', true, false, false],
    'published, exactly at closes_at' => [AssessmentStatus::Published, '-1 hour', '+0 seconds', 'closed', false, false, true],
    'published, after closes_at' => [AssessmentStatus::Published, null, '-1 minute', 'closed', false, false, true],
    'archived' => [AssessmentStatus::Archived, null, '+1 hour', 'archived', false, false, false],
]);

it('derives upcoming/open/closed from status and dates', function (AssessmentStatus $status, ?string $opens, string $closes, string $state, bool $open, bool $upcoming, bool $closed) {
    $now = Carbon::parse('2026-10-04 10:00:00');
    $this->travelTo($now);

    $quiz = Assessment::factory()->create([
        'status' => $status,
        'opens_at' => $opens ? $now->copy()->modify($opens) : null,
        'closes_at' => $now->copy()->modify($closes),
    ]);

    expect($quiz->state())->toBe($state)
        ->and($quiz->isOpen())->toBe($open)
        ->and($quiz->isUpcoming())->toBe($upcoming)
        ->and($quiz->isClosed())->toBe($closed)
        ->and(Assessment::query()->inState($state)->whereKey($quiz->id)->exists())->toBeTrue();
})->with('time states');

it('generates codes from the unambiguous alphabet', function () {
    expect(array_intersect(str_split(AccessCode::ALPHABET), str_split(AccessCode::AMBIGUOUS)))->toBeEmpty();

    $codes = collect(range(1, 200))->flatMap(fn () => [
        AccessCode::generate(AccessCode::ROSTER_LENGTH),
        AccessCode::generate(AccessCode::SHARED_LENGTH),
    ]);

    expect($codes->every(fn (string $code) => preg_match('/^['.AccessCode::ALPHABET.']{6}(['.AccessCode::ALPHABET.']{2})?$/', $code)))->toBeTrue();
});

it('totals marks for the max score', function () {
    $quiz = Assessment::factory()->create();
    $quiz->assessmentQuestions()->createMany([
        ['question_id' => Question::factory()->for($quiz->team)->create()->id, 'position' => 1, 'marks' => 1.5],
        ['question_id' => Question::factory()->for($quiz->team)->create()->id, 'position' => 2, 'marks' => 2],
    ]);

    expect($quiz->maxScore())->toBe(3.5);
});
