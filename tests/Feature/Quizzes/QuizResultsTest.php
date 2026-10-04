<?php

use App\Actions\Grading\RefreshAttemptScore;
use App\Models\AuditLog;
use App\Models\Participant;

test('results list every participant with their total', function () {
    [, $team] = actingAsInstructor();
    $attempt = submittedAttempt([], ['team_id' => $team->id]);
    $attempt->answers()->update(['grading_status' => 'final', 'score' => 1.5]);
    app(RefreshAttemptScore::class)->handle($attempt);
    $quiz = $attempt->participant->assessment;
    Participant::factory()->for($quiz)->withCode()->create();

    $this->get(route('quizzes.results', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/Results')
            ->has('rows', 2)
            ->where('summary.graded', 1)
            ->where('summary.average', 6)
            ->where('rows', fn ($rows) => collect($rows)->pluck('status')->sort()->values()->all() === ['graded', 'not_started']));
});

test('manual results can be released and hidden, with audit logs', function () {
    [, $team] = actingAsInstructor();
    $quiz = submittedAttempt([], ['team_id' => $team->id])->participant->assessment;

    $this->post(route('quizzes.results.release', [$team, $quiz]))->assertRedirect();
    expect($quiz->refresh()->resultsReleased())->toBeTrue();

    $this->post(route('quizzes.results.unrelease', [$team, $quiz]))->assertRedirect();
    expect($quiz->refresh()->resultsReleased())->toBeFalse()
        ->and(AuditLog::pluck('action')->all())->toBe(['results.release', 'results.unrelease']);
});

test('the attempt detail shows answers in the student\'s order', function () {
    [, $team] = actingAsInstructor();
    $attempt = submittedAttempt([], ['team_id' => $team->id]);

    $this->get(route('attempts.show', [$team, $attempt]))
        ->assertInertia(fn ($page) => $page->component('attempts/Show')->has('answers', 4)
            ->where('answers.0.id', $attempt->answers()->where('assessment_question_id', $attempt->question_order[0])->value('id')));
});
