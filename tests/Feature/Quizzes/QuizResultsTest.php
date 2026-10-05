<?php

use App\Actions\Attempts\StartAttempt;
use App\Actions\Grading\RefreshAttemptScore;
use App\Enums\AttemptEventType;
use App\Enums\AttemptStatus;
use App\Enums\ReleaseMode;
use App\Jobs\GradeAttempt;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;

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
    $quiz = submittedAttempt([], ['team_id' => $team->id, 'show_answers_after_release' => false])->participant->assessment;

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

test('automatic-release results can be released early, and hidden again until the release is due', function () {
    [, $team] = actingAsInstructor();
    $quiz = submittedAttempt([], ['team_id' => $team->id, 'release_mode' => ReleaseMode::Automatic, 'show_answers_after_release' => false])->participant->assessment;

    $this->post(route('quizzes.results.release', [$team, $quiz]))->assertRedirect();
    expect($quiz->refresh()->resultsReleased())->toBeTrue();

    $this->post(route('quizzes.results.unrelease', [$team, $quiz]))->assertRedirect();
    expect($quiz->refresh()->resultsReleased())->toBeFalse();

    $this->travelTo($quiz->closes_at->addMinute());
    $this->post(route('quizzes.results.unrelease', [$team, $quiz]))->assertStatus(422);
});

test('results with correct answers cannot be released while students can still take the quiz', function () {
    [, $team] = actingAsInstructor();
    $quiz = submittedAttempt([], ['team_id' => $team->id, 'show_answers_after_release' => true])->participant->assessment;

    $this->post(route('quizzes.results.release', [$team, $quiz]))->assertRedirect();

    expect($quiz->refresh()->results_released_at)->toBeNull();
});

/**
 * A quiz with answers shown after release, closing in 10 minutes, with one 30-minute attempt started now.
 */
function quizWithRunningAttempt(Team $team, array $attributes = []): Attempt
{
    [, $participant] = openQuizWithParticipant(['team_id' => $team->id, 'show_answers_after_release' => true,
        'closes_at' => now()->addMinutes(10), 'duration_minutes' => 30, ...$attributes]);

    return app(StartAttempt::class)->handle($participant)[0];
}

test('releasing submits abandoned attempts first, even if the expiry job never ran', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    $attempt = quizWithRunningAttempt($team);
    $quiz = $attempt->participant->assessment;

    $this->travel(32)->minutes();
    $this->post(route('quizzes.results.release', [$team, $quiz]))->assertRedirect();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Submitted)
        ->and($attempt->events()->where('type', AttemptEventType::AutoSubmitted)->exists())->toBeTrue()
        ->and($quiz->refresh()->results_released_at)->not->toBeNull();
    Queue::assertPushed(GradeAttempt::class, 1);
});

test('results with correct answers cannot be released while a student is still within their time', function () {
    [, $team] = actingAsInstructor();
    $attempt = quizWithRunningAttempt($team);
    $quiz = $attempt->participant->assessment;

    $this->travel(15)->minutes();
    $this->post(route('quizzes.results.release', [$team, $quiz]))
        ->assertInertiaFlash('toast.message', '1 student is still working, and releasing would show them the correct answers. Their time ends at '
            .$attempt->deadline_at->setTimezone($quiz->team->timezone)->format('g:i A').'.');

    expect($quiz->refresh()->results_released_at)->toBeNull()
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::InProgress);
});

test('automatic release does not wait for an abandoned attempt the expiry job has not swept', function () {
    [, $team] = actingAsInstructor();
    $quiz = quizWithRunningAttempt($team, ['release_mode' => ReleaseMode::Automatic])->participant->assessment;

    $this->travel(15)->minutes();
    expect($quiz->autoReleaseDue())->toBeFalse();

    $this->travel(17)->minutes();
    expect($quiz->autoReleaseDue())->toBeTrue();
});
