<?php

use App\Actions\Attempts\StartAttempt;
use App\Enums\AttemptEventType;
use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Question;

function monitoredQuiz(): array
{
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->open()->rosterMode()->create();
    $quiz->assessmentQuestions()->create(['question_id' => Question::factory()->for($team)->create()->id, 'position' => 1, 'marks' => 1]);

    return [$team, $quiz];
}

it('shows each participant\'s progress and activity', function () {
    [$team, $quiz] = monitoredQuiz();
    Participant::factory()->for($quiz)->withCode()->create();
    [$running] = app(StartAttempt::class)->handle(Participant::factory()->for($quiz)->withCode()->create());
    $running->answers()->update(['answered_at' => now(), 'flagged' => true]);
    $running->update(['focus_lost_count' => 4]);
    $running->events()->create(['type' => AttemptEventType::Pasted, 'occurred_at' => now()]);
    Attempt::factory()->for(Participant::factory()->for($quiz)->withCode())->submitted()->create(['auto_submitted' => true]);

    $this->get(route('quizzes.monitor', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/Monitor')
            ->where('summary', ['not_started' => 1, 'in_progress' => 1, 'submitted' => 1])
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['status'] === 'in_progress'
                && $row['answered'] === 1 && $row['total'] === 1 && $row['flagged'] === 1
                && $row['focus_lost'] === 4 && $row['pastes'] === 1)
                && collect($rows)->contains(fn ($row) => $row['status'] === 'submitted' && $row['auto_submitted'])));
});

it('allows a single resume within the window for shared-code quizzes', function () {
    [$team, $quiz] = monitoredQuiz();
    $quiz->update(['access_mode' => 'shared_code', 'shared_code' => 'ACDEFH']);
    $participant = Participant::factory()->for($quiz)->create();
    [$attempt] = app(StartAttempt::class)->handle($participant);

    $this->post(route('quizzes.participants.allow-resume', [$team, $quiz, $participant]))->assertRedirect();
    expect($attempt->fresh()->resume_override_until)->not->toBeNull()
        ->and(AuditLog::where('action', 'attempt.allow_resume')->count())->toBe(1);

    // A new browser (no cookie) resumes once: gets a fresh cookie, the window closes.
    auth()->logout();
    studentSession($participant);
    $this->get(route('student.landing', $quiz->public_id))->assertInertia(fn ($page) => $page->where('state', 'in_progress'));
    $this->post(route('student.resume', $quiz->public_id))
        ->assertRedirect(route('student.question', [$attempt->public_id, 1]))
        ->assertCookie($attempt->cookieName());

    expect($attempt->fresh()->resume_override_until)->toBeNull()
        ->and($attempt->events()->where('type', AttemptEventType::Resumed)->count())->toBe(1);

    // A third browser is blocked again.
    $this->post(route('student.resume', $quiz->public_id))->assertRedirect(route('student.landing', $quiz->public_id));
});

it('expires the resume window', function () {
    [$team, $quiz] = monitoredQuiz();
    $quiz->update(['access_mode' => 'shared_code', 'shared_code' => 'ACDEFH']);
    $participant = Participant::factory()->for($quiz)->create();
    [$attempt] = app(StartAttempt::class)->handle($participant);
    $this->post(route('quizzes.participants.allow-resume', [$team, $quiz, $participant]));

    $this->travel(11)->minutes();
    auth()->logout();
    studentSession($participant);

    $this->get(route('student.landing', $quiz->public_id))->assertInertia(fn ($page) => $page->where('state', 'blocked'));
});

it('resets an attempt after the roll number is confirmed', function () {
    [$team, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create();
    [$attempt] = app(StartAttempt::class)->handle($participant);

    $this->post(route('quizzes.participants.reset', [$team, $quiz, $participant]), ['roll_number' => 'WRONG'])
        ->assertSessionHasErrors('roll_number');

    $this->post(route('quizzes.participants.reset', [$team, $quiz, $participant]), ['roll_number' => strtolower($participant->student->roll_number)])
        ->assertRedirect();

    expect(Attempt::find($attempt->id))->toBeNull()
        ->and($attempt->answers()->count())->toBe(0)
        ->and(AuditLog::where('action', 'attempt.reset')->sole()->changes['before']['status'])->toBe('in_progress');
});

it('force-submits an attempt in progress', function () {
    [$team, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create();
    [$attempt] = app(StartAttempt::class)->handle($participant);

    $this->post(route('quizzes.participants.force-submit', [$team, $quiz, $participant]))->assertRedirect();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Submitted)
        ->and($attempt->events()->where('type', AttemptEventType::ForceSubmitted)->count())->toBe(1)
        ->and(AuditLog::where('action', 'attempt.force_submit')->count())->toBe(1);
});

it('previews the quiz without writing anything or leaking the key', function () {
    [$team, $quiz] = monitoredQuiz();

    $response = $this->get(route('quizzes.preview', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page->component('student/quiz/Preview')->has('questions', 1));

    expect(json_encode($response->viewData('page')['props']['questions']))->not->toContain('is_correct')
        ->and(Attempt::count())->toBe(0)
        ->and(Participant::count())->toBe(0)
        ->and($quiz->questions()->first()->isLocked())->toBeFalse();
});

it('keeps monitor actions inside the course', function (string $method, string $route) {
    [$team] = monitoredQuiz();
    $foreign = Participant::factory()->for(Assessment::factory()->open())->withCode()->create();

    $this->{$method}(route($route, [$team, $foreign->assessment, $foreign]))->assertNotFound();
    $this->{$method}(route($route, [$foreign->assessment->team, $foreign->assessment, $foreign]))->assertForbidden();
})->with([
    ['post', 'quizzes.participants.allow-resume'],
    ['post', 'quizzes.participants.reset'],
    ['post', 'quizzes.participants.force-submit'],
]);

it('keeps the monitor and preview inside the course', function (string $route) {
    [$team] = monitoredQuiz();
    $foreign = Assessment::factory()->open()->create();

    $this->get(route($route, [$team, $foreign]))->assertNotFound();
    $this->get(route($route, [$foreign->team, $foreign]))->assertForbidden();
})->with(['quizzes.monitor', 'quizzes.preview']);

it('does not reach a participant of another quiz in the same course', function () {
    [$team, $quiz] = monitoredQuiz();
    $other = Participant::factory()->for(Assessment::factory()->for($team)->open())->withCode()->create();

    $this->post(route('quizzes.participants.force-submit', [$team, $quiz, $other]))->assertNotFound();
    $this->post(route('quizzes.participants.reset', [$team, $quiz, $other]), ['roll_number' => 'X'])->assertNotFound();
});

it('explains stale monitor actions instead of failing', function () {
    [$team, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create();
    Attempt::factory()->for($participant)->submitted()->create();

    $this->post(route('quizzes.participants.force-submit', [$team, $quiz, $participant]))->assertRedirect();
    $this->post(route('quizzes.participants.allow-resume', [$team, $quiz, $participant]))->assertRedirect();

    expect(AuditLog::count())->toBe(0);
});

it('refuses to reset once the quiz has closed', function () {
    [$team, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create();
    $attempt = Attempt::factory()->for($participant)->submitted()->create();
    $quiz->update(['closes_at' => now()->subMinute()]);

    $this->post(route('quizzes.participants.reset', [$team, $quiz, $participant]), ['roll_number' => $participant->student->roll_number])->assertRedirect();

    expect($attempt->fresh())->not->toBeNull();
});

it('sends a student whose attempt was reset back to the start', function () {
    [, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create();
    [$attempt] = app(StartAttempt::class)->handle($participant);
    $attempt->delete();

    auth()->logout();
    studentSession($participant);

    $this->putJson(route('student.answers.save', [$attempt->public_id, 1]), [])
        ->assertStatus(409)->assertJson(['reason' => 'reset', 'redirect' => route('student.landing', $quiz->public_id)]);
    $this->get(route('student.question', [$attempt->public_id, 1]))->assertRedirect(route('student.landing', $quiz->public_id));
});
