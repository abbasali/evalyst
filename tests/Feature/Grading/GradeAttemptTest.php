<?php

use App\Actions\Grading\GradeAttempt;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Jobs\GradeOpenAnswer;
use App\Models\Attempt;
use Illuminate\Support\Facades\Queue;

function correctOptionIds(Attempt $attempt, string $type): array
{
    return answerOfType($attempt, $type)->question->options()->where('is_correct', true)->pluck('id')->all();
}

test('choice answers are scored and blank open answers get zero without AI', function () {
    Queue::fake();
    $attempt = submittedAttempt();
    answerOfType($attempt, 'single_choice')->update(['selected_option_ids' => correctOptionIds($attempt, 'single_choice')]);
    answerOfType($attempt, 'open_text')->update(['text_answer' => "   \n "]);

    app(GradeAttempt::class)->handle($attempt);

    $attempt->refresh();
    $text = answerOfType($attempt, 'open_text');
    expect($attempt->status)->toBe(AttemptStatus::Graded)
        ->and((float) $attempt->score)->toBe(2.0)
        ->and($text->grading_status)->toBe(AnswerGradingStatus::Final)
        ->and((float) $text->score)->toBe(0.0)
        ->and($text->feedback)->toBe('No answer submitted.')
        ->and($attempt->answers()->whereNull('published_at')->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('non-blank open answers are queued once each and the attempt waits in grading', function () {
    Queue::fake();
    $attempt = submittedAttempt([
        'open_text' => ['text_answer' => 'It maps values.'],
        'open_code' => ['code_answer' => '<?php echo 1;'],
    ]);

    app(GradeAttempt::class)->handle($attempt);
    app(GradeAttempt::class)->handle($attempt);

    expect($attempt->refresh()->status)->toBe(AttemptStatus::Grading)
        ->and($attempt->score)->toBeNull()
        ->and(answerOfType($attempt, 'open_text')->grading_status)->toBe(AnswerGradingStatus::Pending);

    Queue::assertPushedTimes(GradeOpenAnswer::class, 2);
    Queue::assertPushedOn('ai', GradeOpenAnswer::class);
});

test('AI jobs go to the configured queue', function () {
    Queue::fake();
    config(['evalyst.ai.queue' => 'default']);
    $attempt = submittedAttempt(['open_text' => ['text_answer' => 'It maps values.']]);

    app(GradeAttempt::class)->handle($attempt);

    Queue::assertPushedOn('default', GradeOpenAnswer::class);
});

test('an attempt still in progress is not graded', function () {
    Queue::fake();
    $attempt = submittedAttempt();
    $attempt->update(['status' => AttemptStatus::InProgress]);

    app(GradeAttempt::class)->handle($attempt);

    expect($attempt->refresh()->status)->toBe(AttemptStatus::InProgress)
        ->and($attempt->answers()->where('grading_status', AnswerGradingStatus::Ungraded)->count())->toBe(4);
});
