<?php

use App\Actions\Attempts\StartAttempt;
use App\Enums\AttemptEventType;
use App\Enums\AttemptStatus;
use App\Jobs\GradeAttempt;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Question;
use Illuminate\Support\Facades\Queue;

function startedAttempt(array $attributes = []): array
{
    [$quiz, $participant] = openQuizWithParticipant($attributes);
    [$attempt] = app(StartAttempt::class)->handle($participant);
    studentSession($participant);

    return [$quiz, $participant, $attempt];
}

it('starts an attempt with a capped deadline, answer rows and a resume cookie', function () {
    $this->travelTo('2026-10-10 10:00:00');
    [$quiz, $participant] = openQuizWithParticipant(['duration_minutes' => 60, 'opens_at' => '2026-10-10 09:00:00', 'closes_at' => '2026-10-10 10:30:00']);
    studentSession($participant);

    $this->post(route('student.start', $quiz->public_id))
        ->assertCookie('attempt_'.$participant->fresh()->attempt->public_id)
        ->assertRedirect();

    $attempt = $participant->fresh()->attempt;
    expect($attempt->deadline_at->toDateTimeString())->toBe('2026-10-10 10:30:00')
        ->and($attempt->answers()->count())->toBe(4)
        ->and((float) $attempt->max_score)->toBe(8.0)
        ->and($attempt->question_order)->toBe($quiz->assessmentQuestions()->pluck('id')->all());

    $this->post(route('student.start', $quiz->public_id))->assertRedirect(route('student.question', [$attempt->public_id, 1]));
    expect(Attempt::count())->toBe(1);
});

it('snapshots shuffled question and option order at the start', function () {
    [, , $attempt] = startedAttempt(['shuffle_questions' => true, 'shuffle_options' => true]);

    expect($attempt->question_order)->toHaveCount(4)
        ->and($attempt->option_order)->toHaveCount(2);

    $first = $this->get(route('student.question', [$attempt->public_id, 1]))->viewData('page')['props']['question'];
    $again = $this->get(route('student.question', [$attempt->public_id, 1]))->viewData('page')['props']['question'];
    expect($again)->toBe($first);
});

it('never sends the answer key to the student', function (int $position) {
    [, , $attempt] = startedAttempt();

    $props = $this->get(route('student.question', [$attempt->public_id, $position]))
        ->assertInertia(fn ($page) => $page->component('student/quiz/Attempt')->has('attempt.deadline_at')->has('attempt.server_now'))
        ->viewData('page')['props'];

    $json = json_encode($props);
    expect($json)->not->toContain('is_correct')
        ->not->toContain('model_answer')
        ->not->toContain('rubric')
        ->not->toContain('explanation');
})->with([1, 2, 3, 4]);

it('404s outside the question range and 403s for someone else\'s attempt', function () {
    [, , $attempt] = startedAttempt();

    $this->get(route('student.question', [$attempt->public_id, 0]))->assertNotFound();
    $this->get(route('student.question', [$attempt->public_id, 5]))->assertNotFound();

    $other = Attempt::factory()->for(Participant::factory()->for(Assessment::factory()->open()))->create();
    $this->get(route('student.question', [$other->public_id, 1]))->assertForbidden();
});

it('saves answers by type and locks the question', function () {
    [, , $attempt] = startedAttempt();
    $answerAt = fn (int $position) => $attempt->answers()->where('assessment_question_id', $attempt->question_order[$position - 1])->with('question.options')->sole();
    $save = fn (int $position, array $data) => $this->putJson(route('student.answers.save', [$attempt->public_id, $position]), $data);

    // Options are sent and received by displayed index, never by ID.
    $save(1, ['selected_option_ids' => [0, 1]])->assertUnprocessable();
    $save(1, ['selected_option_ids' => [7]])->assertUnprocessable();
    $save(1, ['selected_option_ids' => [2], 'flagged' => true])->assertOk();
    $save(2, ['selected_option_ids' => [0, 3]])->assertOk();
    $save(3, ['text_answer' => str_repeat('a', 10001)])->assertUnprocessable();
    $save(3, ['text_answer' => 'Strict compares types too.'])->assertOk();
    $save(4, ['text_answer' => 'Uses auth', 'code_answer' => '<?php echo 1;'])->assertOk()->assertJson(['answered' => true]);
    $save(5, ['text_answer' => 'x'])->assertNotFound();

    $single = $answerAt(1);
    expect($single->flagged)->toBeTrue()
        ->and($single->selected_option_ids)->toBe([$single->question->options[2]->id])
        ->and($answerAt(2)->selected_option_ids)->toBe([$answerAt(2)->question->options[0]->id, $answerAt(2)->question->options[3]->id])
        ->and($answerAt(4)->code_answer)->toBe('<?php echo 1;')
        ->and($single->question->isLocked())->toBeTrue();

    $this->get(route('student.question', [$attempt->public_id, 1]))
        ->assertInertia(fn ($page) => $page->where('answer.selected_option_ids', [2])->where('question.options.2.id', 2));

    $save(3, ['text_answer' => '   '])->assertOk()->assertJson(['answered' => false]);
    expect($answerAt(3)->answered_at)->toBeNull();
});

it('answers background saves with JSON when the session is gone or the attempt moved', function () {
    [, , $attempt] = startedAttempt();
    $url = route('student.answers.save', [$attempt->public_id, 1]);

    $this->flushSession();
    $this->putJson($url, [])->assertUnauthorized()->assertJson(['reason' => 'session']);
});

it('accepts saves within the grace window and auto-submits after it', function () {
    [, , $attempt] = startedAttempt();
    $answer = $attempt->answers()->where('assessment_question_id', $attempt->question_order[2])->sole();
    $url = route('student.answers.save', [$attempt->public_id, 3]);

    $this->travelTo($attempt->deadline_at->addSeconds(30));
    $this->putJson($url, ['text_answer' => 'Just in time'])->assertOk();

    $this->travelTo($attempt->deadline_at->addSeconds(31));
    $this->putJson($url, ['text_answer' => 'Too late'])
        ->assertStatus(409)
        ->assertJson(['redirect' => route('student.done', $attempt->public_id)]);

    expect($answer->fresh()->text_answer)->toBe('Just in time')
        ->and($attempt->fresh()->status)->not->toBe(AttemptStatus::InProgress)
        ->and($attempt->fresh()->auto_submitted)->toBeTrue();
});

it('enforces one-way navigation on the server', function () {
    [, , $attempt] = startedAttempt(['one_way_navigation' => true]);

    $this->get(route('student.question', [$attempt->public_id, 3]))->assertRedirect(route('student.question', [$attempt->public_id, 1]));
    $this->get(route('student.question', [$attempt->public_id, 2]))->assertOk();
    $this->get(route('student.question', [$attempt->public_id, 1]))->assertRedirect(route('student.question', [$attempt->public_id, 2]));
    $this->get(route('student.review', $attempt->public_id))->assertRedirect(route('student.question', [$attempt->public_id, 2]));

    $this->putJson(route('student.answers.save', [$attempt->public_id, 1]), ['selected_option_ids' => [0]])->assertStatus(409)->assertJson(['reason' => 'closed']);
    expect($attempt->answers()->where('assessment_question_id', $attempt->question_order[0])->value('selected_option_ids'))->toBeNull();
});

it('submits once, queues grading and closes the attempt routes', function () {
    Queue::fake();
    [, , $attempt] = startedAttempt();

    $this->get(route('student.review', $attempt->public_id))
        ->assertInertia(fn ($page) => $page->component('student/quiz/Review')->has('map', 4));

    $this->post(route('student.submit', $attempt->public_id))->assertRedirect(route('student.done', $attempt->public_id));
    $this->post(route('student.submit', $attempt->public_id), ['auto' => true])->assertRedirect(route('student.done', $attempt->public_id));

    Queue::assertPushed(GradeAttempt::class, 1);
    expect($attempt->fresh()->auto_submitted)->toBeFalse()
        ->and($attempt->events()->count())->toBe(0);

    $this->get(route('student.question', [$attempt->public_id, 1]))->assertRedirect(route('student.done', $attempt->public_id));
    $this->putJson(route('student.answers.save', [$attempt->public_id, 1]), ['selected_option_ids' => [0]])->assertStatus(409)->assertJson(['reason' => 'expired']);
    expect($attempt->answers()->whereNotNull('answered_at')->count())->toBe(0);
    $this->get(route('student.done', $attempt->public_id))
        ->assertInertia(fn ($page) => $page->component('student/quiz/Done')->where('resultsUrl', url('/results/'.$attempt->participant->public_id)));
});

it('expires overdue attempts exactly once', function () {
    Queue::fake();
    $overdue = Attempt::factory()->create(['deadline_at' => now()->subMinutes(2)]);
    $running = Attempt::factory()->create(['deadline_at' => now()->addMinutes(5)]);

    $this->artisan('attempts:expire')->assertSuccessful();
    $this->artisan('attempts:expire')->assertSuccessful();

    expect($overdue->fresh()->status)->toBe(AttemptStatus::Submitted)
        ->and($overdue->events()->where('type', AttemptEventType::AutoSubmitted)->count())->toBe(1)
        ->and($running->fresh()->status)->toBe(AttemptStatus::InProgress);
    Queue::assertPushed(GradeAttempt::class, 1);
});

it('lets shared-code attempts continue only in the browser that started them', function () {
    $quiz = Assessment::factory()->open()->sharedCode()->create();
    $quiz->assessmentQuestions()->create(['question_id' => Question::factory()->for($quiz->team)->create()->id, 'position' => 1, 'marks' => 1]);
    $participant = Participant::factory()->for($quiz)->create();
    studentSession($participant);

    $start = $this->post(route('student.start', $quiz->public_id));
    $attempt = $participant->fresh()->attempt;
    $cookie = $start->getCookie($attempt->cookieName(), false)->getValue();

    // Another browser (no cookie): blocked.
    $this->get(route('student.question', [$attempt->public_id, 1]))->assertRedirect(route('student.landing', $quiz->public_id));
    $this->get(route('student.landing', $quiz->public_id))->assertInertia(fn ($page) => $page->where('state', 'blocked'));

    $this->putJson(route('student.answers.save', [$attempt->public_id, 1]), [])->assertStatus(409)->assertJson(['reason' => 'elsewhere']);

    // Same browser: fine.
    $this->withUnencryptedCookie($attempt->cookieName(), $cookie)
        ->get(route('student.question', [$attempt->public_id, 1]))->assertOk();
});
