<?php

use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Models\AuditLog;

test('accepting the AI grade publishes it and finishes the attempt', function () {
    [$user, $team] = actingAsInstructor();
    $attempt = attemptNeedingReview(['team_id' => $team->id]);
    $answer = answerOfType($attempt, 'open_text');

    $this->post(route('review.answers.accept', [$team, $answer]))->assertRedirect();

    $answer->refresh();
    expect($answer->grading_status)->toBe(AnswerGradingStatus::Final)
        ->and((float) $answer->score)->toBe(1.5)
        ->and($answer->feedback)->toBe('Mostly right.')
        ->and($answer->graded_by)->toBe($user->id)
        ->and($attempt->refresh()->status)->toBe(AttemptStatus::Graded)
        ->and((float) $attempt->score)->toBe(4.5);

    $log = AuditLog::sole();
    expect($log->action)->toBe('grade.accept')
        ->and($log->changes['after']['score'])->toBe(1.5)
        ->and($log->changes['before']['score'])->toBeNull();
});

test('an instructor can set their own grade within range', function () {
    [, $team] = actingAsInstructor();
    $answer = answerOfType(attemptNeedingReview(['team_id' => $team->id]), 'open_text');

    $this->put(route('review.answers.update', [$team, $answer]), ['score' => 2.5, 'feedback' => 'x'])->assertSessionHasErrors('score');
    $this->put(route('review.answers.update', [$team, $answer]), ['score' => 0.3])->assertSessionHasErrors('score');
    $this->put(route('review.answers.update', [$team, $answer]), ['score' => 0.5, 'feedback' => 'Needs an example.'])->assertSessionHasNoErrors();

    expect((float) $answer->refresh()->score)->toBe(0.5)
        ->and(AuditLog::sole()->action)->toBe('grade.override');

    $this->get(route('review.answers.show', [$team, $answer]))
        ->assertInertia(fn ($page) => $page->component('review/Answer')->has('audit', 1)->where('audit.0.action', 'grade.override'));
});

test('another course cannot open or grade the answer', function () {
    $answer = answerOfType(attemptNeedingReview(), 'open_text');
    [, $team] = actingAsInstructor();

    $this->get(route('review.answers.show', [$team, $answer]))->assertNotFound();
    $this->post(route('review.answers.accept', [$team, $answer]))->assertNotFound();
    $this->get(route('attempts.show', [$team, $answer->attempt_id]))->assertNotFound();
});

test('a grade cannot be set while the student is still taking the quiz', function () {
    [, $team] = actingAsInstructor();
    $attempt = attemptNeedingReview(['team_id' => $team->id]);
    $attempt->update(['status' => 'in_progress']);

    $this->put(route('review.answers.update', [$team, answerOfType($attempt, 'open_text')]), ['score' => 1])
        ->assertSessionHasErrors('score');
});

test('the question filter only lists this course\'s quizzes', function () {
    $other = attemptNeedingReview();
    [, $team] = actingAsInstructor();

    $this->get(route('review.index', [$team, 'assessment' => $other->participant->assessment_id]))
        ->assertInertia(fn ($page) => $page->has('questions', 0));
});
