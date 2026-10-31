<?php

use App\Actions\Attempts\StartAttempt;
use App\Enums\AttemptEventType;
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

    expect($attempt->fresh()->submitted_at)->not->toBeNull()
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
    ['post', 'quizzes.participants.allow-late-start'],
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

it('shows a live score from choice answers while the student is working', function () {
    [$team, $quiz] = monitoredQuiz();
    [$attempt] = app(StartAttempt::class)->handle(Participant::factory()->for($quiz)->withCode()->create());
    $answer = $attempt->answers()->first();
    $answer->update(['selected_option_ids' => [$answer->question->options()->where('is_correct', true)->value('id')]]);

    $this->get(route('quizzes.monitor', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.score', 1)
            ->where('rows.0.max_score', 1)
            ->where('rows.0.score_state', 'live'));
});

it('counts only settled grades for a submitted attempt that is still grading', function () {
    [$team, $quiz] = monitoredQuiz();
    $quiz->assessmentQuestions()->create(['question_id' => Question::factory()->openText()->for($team)->create()->id, 'position' => 2, 'marks' => 2]);
    [$attempt] = app(StartAttempt::class)->handle(Participant::factory()->for($quiz)->withCode()->create());
    $attempt->update(['status' => 'grading', 'submitted_at' => now()]);
    $attempt->answers()->orderBy('id')->first()->update(['grading_status' => 'final', 'score' => 1]);
    $attempt->answers()->orderByDesc('id')->first()->update(['grading_status' => 'pending']);

    $this->get(route('quizzes.monitor', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page->where('rows.0.score', 1)->where('rows.0.score_state', 'grading'));
});

it('lets one roster student join and start after the quiz closed', function () {
    [$team, $quiz] = monitoredQuiz();
    $quiz->update(['closes_at' => now()->subMinute()]);
    $late = Participant::factory()->for($quiz)->withCode()->create();
    $other = Participant::factory()->for($quiz)->withCode()->create();
    $until = now()->setTimezone($quiz->team->timezone)->addHour()->startOfMinute();

    $this->post(route('quizzes.participants.allow-late-start', [$team, $quiz, $late]), ['until' => $until->format('Y-m-d\TH:i')])->assertRedirect();
    expect($late->fresh()->deadline_override_at->equalTo($until))->toBeTrue()
        ->and(AuditLog::where('action', 'attempt.allow_late_start')->count())->toBe(1);

    auth()->logout();
    $this->post(route('student.join.store'), ['code' => $other->access_code])->assertSessionHasErrors(['code' => 'This quiz has closed.']);
    $this->post(route('student.join.store'), ['code' => $late->access_code])->assertSessionHasNoErrors();
    $this->get(route('student.landing', $quiz->public_id))
        ->assertInertia(fn ($page) => $page->where('state', 'not_started')->where('quiz.closes_at', $until->utc()->toIso8601String()));
    $this->post(route('student.start', $quiz->public_id))->assertRedirect();

    expect($late->attempt()->exists())->toBeTrue();
});

it('stops a late start once the override passes', function () {
    [, $quiz] = monitoredQuiz();
    $quiz->update(['closes_at' => now()->subMinute()]);
    $participant = Participant::factory()->for($quiz)->withCode()->create(['deadline_override_at' => now()->addMinutes(5)]);

    $this->travel(6)->minutes();
    auth()->logout();
    studentSession($participant);

    $this->get(route('student.landing', $quiz->public_id))->assertInertia(fn ($page) => $page->where('state', 'closed'));
    $this->post(route('student.start', $quiz->public_id))->assertSessionHasErrors('start');
});

it('only allows late starts after closing time on roster quizzes', function () {
    [$team, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create();
    $local = fn ($time) => $time->setTimezone($quiz->team->timezone)->format('Y-m-d\TH:i');

    // Before the quiz closes (it is still open for a while).
    $this->post(route('quizzes.participants.allow-late-start', [$team, $quiz, $participant]), ['until' => $local($quiz->closes_at->subMinute())])
        ->assertSessionHasErrors('until');

    $shared = Assessment::factory()->for($team)->open()->create(['access_mode' => 'shared_code', 'shared_code' => 'ACDEFH']);
    $joined = Participant::factory()->for($shared)->create();
    $this->post(route('quizzes.participants.allow-late-start', [$team, $shared, $joined]), ['until' => $local($shared->closes_at->addHour())])
        ->assertRedirect()->assertInertiaFlash('toast.type', 'error');

    expect($participant->fresh()->deadline_override_at)->toBeNull()
        ->and($joined->fresh()->deadline_override_at)->toBeNull();
});

it('removes a late start', function () {
    [$team, $quiz] = monitoredQuiz();
    $participant = Participant::factory()->for($quiz)->withCode()->create(['deadline_override_at' => $quiz->closes_at->addHour()]);

    $this->post(route('quizzes.participants.allow-late-start', [$team, $quiz, $participant]), ['until' => null])
        ->assertRedirect()->assertInertiaFlash('toast.type', 'success');

    expect($participant->fresh()->deadline_override_at)->toBeNull()
        ->and(AuditLog::where('action', 'attempt.allow_late_start')->sole()->changes['after']['deadline_override_at'])->toBeNull();
});

it('refuses a late start once results are released', function () {
    [$team, $quiz] = monitoredQuiz();
    $quiz->update(['closes_at' => now()->subMinute(), 'results_released_at' => now()]);
    $participant = Participant::factory()->for($quiz)->withCode()->create();

    $this->post(route('quizzes.participants.allow-late-start', [$team, $quiz, $participant]), ['until' => now()->setTimezone($quiz->team->timezone)->addHour()->format('Y-m-d\TH:i')])
        ->assertInertiaFlash('toast.type', 'error');

    expect($participant->fresh()->deadline_override_at)->toBeNull();
});

it('blocks releasing answers and archiving while a late student has not started', function () {
    [$team, $quiz] = monitoredQuiz();
    $quiz->update(['closes_at' => now()->subMinute(), 'show_answers_after_release' => true]);
    Participant::factory()->for($quiz)->withCode()->create(['deadline_override_at' => now()->addHour()]);

    $this->post(route('quizzes.results.release', [$team, $quiz]))->assertInertiaFlash('toast.type', 'error');
    $this->post(route('quizzes.archive', [$team, $quiz]));

    expect($quiz->fresh()->results_released_at)->toBeNull()
        ->and($quiz->fresh()->isArchived())->toBeFalse();
});

it('resets an attempt after closing only when the student may start late', function () {
    [$team, $quiz] = monitoredQuiz();
    $late = Participant::factory()->for($quiz)->withCode()->create(['deadline_override_at' => now()->addHour()]);
    $other = Participant::factory()->for($quiz)->withCode()->create();
    Attempt::factory()->for($late)->submitted()->create();
    Attempt::factory()->for($other)->submitted()->create();
    $quiz->update(['closes_at' => now()->subMinute()]);

    $this->post(route('quizzes.participants.reset', [$team, $quiz, $late]), ['roll_number' => $late->student->roll_number])
        ->assertInertiaFlash('toast.type', 'success');
    $this->post(route('quizzes.participants.reset', [$team, $quiz, $other]), ['roll_number' => $other->student->roll_number])
        ->assertInertiaFlash('toast.type', 'error');

    expect($late->attempt()->exists())->toBeFalse()
        ->and($other->attempt()->exists())->toBeTrue();

    auth()->logout();
    studentSession($late);
    $this->post(route('student.start', $quiz->public_id))->assertSessionHasNoErrors();
    expect($late->attempt()->exists())->toBeTrue();
});
