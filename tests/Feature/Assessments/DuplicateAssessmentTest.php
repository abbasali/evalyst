<?php

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssignmentRule;

test('duplicating a quiz shares its questions and leaves participants behind', function () {
    [, $team] = actingAsInstructor();
    [$quiz] = openQuizWithParticipant(['team_id' => $team->id, 'results_released_at' => now()]);

    $this->post(route('assessments.duplicate', [$team, $quiz]))->assertRedirect();

    $copy = Assessment::latest('id')->first();
    expect($copy->id)->not->toBe($quiz->id)
        ->and($copy->status)->toBe(AssessmentStatus::Draft)
        ->and($copy->title)->toBe("Copy of {$quiz->title}")
        ->and($copy->public_id)->not->toBe($quiz->public_id)
        ->and($copy->participants()->count())->toBe(0)
        ->and($copy->results_released_at)->toBeNull()
        ->and($copy->assessmentQuestions()->pluck('question_id')->all())->toBe($quiz->assessmentQuestions()->pluck('question_id')->all())
        ->and($quiz->refresh()->participants()->count())->toBe(1);
});

test('duplicating an assignment copies its rules', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->sharedCode()->for($team)->create();
    AssignmentRule::factory()->count(2)->for($assignment, 'assessment')->create();

    $this->post(route('assessments.duplicate', [$team, $assignment]))->assertRedirect();

    $copy = Assessment::latest('id')->first();
    expect($copy->rules()->count())->toBe(2)
        ->and($copy->shared_code)->not->toBe($assignment->shared_code);
});

test('another course cannot duplicate or export', function () {
    $quiz = Assessment::factory()->create();
    [, $team] = actingAsInstructor();

    $this->post(route('assessments.duplicate', [$team, $quiz]))->assertNotFound();
    $this->get(route('assessments.export', [$team, $quiz]))->assertNotFound();
});
