<?php

use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Jobs\GradeOpenAnswer;
use Illuminate\Support\Facades\Queue;

test('stale pending answers are re-dispatched once', function () {
    $attempt = submittedAttempt();
    $stale = answerOfType($attempt, 'open_text');
    $fresh = answerOfType($attempt, 'open_code');
    $stale->update(['grading_status' => AnswerGradingStatus::Pending]);
    $this->travel(10)->minutes();
    $fresh->update(['grading_status' => AnswerGradingStatus::Pending]);
    $this->travel(10)->minutes();
    Queue::fake();

    $this->artisan('grading:recover')->assertSuccessful();
    $this->artisan('grading:recover')->assertSuccessful();

    Queue::assertPushedTimes(GradeOpenAnswer::class, 1);
    Queue::assertPushed(GradeOpenAnswer::class, fn ($job) => $job->answerId === $stale->id);
});

test('an attempt stuck in grading with nothing pending is settled', function () {
    $attempt = submittedAttempt();
    $attempt->answers()->update(['grading_status' => AnswerGradingStatus::Final, 'score' => 1]);
    $attempt->update(['status' => AttemptStatus::Grading]);
    $this->travel(20)->minutes();

    $this->artisan('grading:recover')->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(AttemptStatus::Graded)->and((float) $attempt->score)->toBe(4.0);
});
