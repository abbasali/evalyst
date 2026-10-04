<?php

use App\Actions\Attempts\StartAttempt;
use App\Enums\AttemptEventType;

it('records reported activity with server timestamps and counts focus losses', function () {
    [, $participant] = openQuizWithParticipant();
    [$attempt] = app(StartAttempt::class)->handle($participant);
    studentSession($participant);

    $this->postJson(route('student.events.store', $attempt->public_id), ['events' => [
        ['type' => 'focus_lost'],
        ['type' => 'focus_returned'],
        ['type' => 'pasted', 'position' => 3, 'length' => 420],
        ['type' => 'fullscreen_exited'],
    ]])->assertOk()->assertJson(['recorded' => 4]);

    expect($attempt->fresh()->focus_lost_count)->toBe(1)
        ->and($attempt->events()->where('type', AttemptEventType::Pasted)->sole()->meta)->toEqualCanonicalizing(['position' => 3, 'length' => 420]);
});

it('rejects server-only event types, oversized batches and finished attempts', function () {
    [, $participant] = openQuizWithParticipant();
    [$attempt] = app(StartAttempt::class)->handle($participant);
    studentSession($participant);
    $url = route('student.events.store', $attempt->public_id);

    $this->postJson($url, ['events' => [['type' => 'auto_submitted']]])->assertUnprocessable();
    $this->postJson($url, ['events' => array_fill(0, 21, ['type' => 'focus_lost'])])->assertUnprocessable();

    $attempt->update(['status' => 'submitted']);
    $this->postJson($url, ['events' => [['type' => 'focus_lost']]])->assertStatus(409);

    expect($attempt->events()->count())->toBe(0);
});

it('throttles event floods', function () {
    [, $participant] = openQuizWithParticipant();
    [$attempt] = app(StartAttempt::class)->handle($participant);
    studentSession($participant);
    $url = route('student.events.store', $attempt->public_id);

    foreach (range(1, 30) as $ignored) {
        $this->postJson($url, ['events' => [['type' => 'focus_lost']]]);
    }

    $this->postJson($url, ['events' => [['type' => 'focus_lost']]])->assertStatus(429);
});

it('throttles per attempt, not per IP', function () {
    [, $first] = openQuizWithParticipant();
    [, $second] = openQuizWithParticipant();
    [$firstAttempt] = app(StartAttempt::class)->handle($first);
    [$secondAttempt] = app(StartAttempt::class)->handle($second);

    studentSession($first);
    foreach (range(1, 30) as $ignored) {
        $this->postJson(route('student.events.store', $firstAttempt->public_id), ['events' => [['type' => 'focus_lost']]]);
    }

    studentSession($second);
    $this->postJson(route('student.events.store', $secondAttempt->public_id), ['events' => [['type' => 'focus_lost']]])->assertOk();
});
