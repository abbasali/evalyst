<?php

use App\Actions\Grading\GradeAttempt;
use App\Ai\Agents\OpenAnswerGrader;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Jobs\GradeOpenAnswer;
use App\Models\AiRun;
use App\Models\Attempt;
use Illuminate\Support\Facades\Queue;

/**
 * A graded attempt whose only unsettled answer is a pending open_text answer.
 */
function attemptWithPendingText(): Attempt
{
    $queue = Queue::getFacadeRoot();
    Queue::fake();
    $attempt = submittedAttempt(['open_text' => ['text_answer' => 'array_map applies a callback.']]);
    app(GradeAttempt::class)->handle($attempt);
    Queue::swap($queue);

    return $attempt->refresh();
}

function grade(float $score, float $confidence, array $flags = []): array
{
    return ['score' => $score, 'feedback' => 'Clear explanation.', 'breakdown' => [['criterion' => 'Idea', 'awarded' => $score, 'max' => 2, 'note' => '']], 'confidence' => $confidence, 'flags' => $flags];
}

test('a confident grade is published and the attempt is graded', function () {
    config(['evalyst.ai.pricing.gpt-5.4-mini' => ['input' => 1, 'output' => 1]]);
    $attempt = attemptWithPendingText();
    $answer = answerOfType($attempt, 'open_text');
    OpenAnswerGrader::fake([grade(1.5, 0.92)]);

    GradeOpenAnswer::dispatchSync($answer->id);

    $answer->refresh();
    expect($answer->grading_status)->toBe(AnswerGradingStatus::Final)
        ->and((float) $answer->score)->toBe(1.5)
        ->and($answer->feedback)->toBe('Clear explanation.')
        ->and($answer->published_at)->not->toBeNull()
        ->and($attempt->refresh()->status)->toBe(AttemptStatus::Graded)
        ->and((float) $attempt->score)->toBe(1.5);

    $run = AiRun::sole();
    expect($run->succeeded)->toBeTrue()
        ->and($run->team_id)->toBe($attempt->participant->assessment->team_id)
        ->and($run->subject_id)->toBe($answer->id);
});

test('low confidence or a flag sends the answer to review', function (float $confidence, array $flags, array $reasons) {
    $attempt = attemptWithPendingText();
    $answer = answerOfType($attempt, 'open_text');
    OpenAnswerGrader::fake([grade(1, $confidence, $flags)]);

    GradeOpenAnswer::dispatchSync($answer->id);

    $answer->refresh();
    expect($answer->grading_status)->toBe(AnswerGradingStatus::NeedsReview)
        ->and($answer->score)->toBeNull()
        ->and((float) $answer->ai_score)->toBe(1.0)
        ->and($answer->review_reasons)->toBe($reasons)
        ->and($attempt->refresh()->status)->toBe(AttemptStatus::Grading);
})->with([
    'low confidence' => [0.5, [], ['low_confidence']],
    'prompt injection' => [0.95, ['prompt_injection'], ['flag:prompt_injection']],
]);

test('the assessment threshold overrides the default', function () {
    $attempt = attemptWithPendingText();
    $attempt->participant->assessment->update(['auto_publish_threshold' => 0.95]);
    OpenAnswerGrader::fake([grade(2, 0.9)]);

    GradeOpenAnswer::dispatchSync(answerOfType($attempt, 'open_text')->id);

    expect(answerOfType($attempt, 'open_text')->grading_status)->toBe(AnswerGradingStatus::NeedsReview);
});

test('invalid output fails the answer at once', function () {
    $attempt = attemptWithPendingText();
    OpenAnswerGrader::fake([grade(5, 0.99)]);

    GradeOpenAnswer::dispatchSync(answerOfType($attempt, 'open_text')->id);

    $answer = answerOfType($attempt, 'open_text');
    expect($answer->grading_status)->toBe(AnswerGradingStatus::Failed)
        ->and($answer->grading_error)->toContain('unusable')
        ->and($answer->score)->toBeNull();
    OpenAnswerGrader::assertPrompted(fn () => true);
});

test('a final failure marks the answer failed and logs a failed run', function () {
    $attempt = attemptWithPendingText();
    $answer = answerOfType($attempt, 'open_text');
    OpenAnswerGrader::fake(fn () => throw new RuntimeException('OpenAI is down'));

    $job = new GradeOpenAnswer($answer->id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('OpenAI is down'));

    expect($answer->refresh()->grading_status)->toBe(AnswerGradingStatus::Failed)
        ->and($answer->grading_error)->not->toContain('OpenAI')
        ->and(AiRun::sole()->succeeded)->toBeFalse()
        ->and($attempt->refresh()->status)->toBe(AttemptStatus::Grading);
});

test('an answer that is no longer pending is skipped', function () {
    $attempt = attemptWithPendingText();
    $answer = answerOfType($attempt, 'open_text');
    $answer->update(['grading_status' => AnswerGradingStatus::Final, 'score' => 2]);
    OpenAnswerGrader::fake()->preventStrayPrompts();

    GradeOpenAnswer::dispatchSync($answer->id);

    expect(AiRun::count())->toBe(0)->and((float) $answer->refresh()->score)->toBe(2.0);
});

test('a retry reuses a stored suggestion instead of calling the AI again', function () {
    $attempt = attemptWithPendingText();
    $answer = answerOfType($attempt, 'open_text');
    $answer->update(['ai_score' => 2, 'ai_feedback' => 'Saved.', 'ai_confidence' => 0.9, 'ai_flags' => []]);
    OpenAnswerGrader::fake()->preventStrayPrompts();

    GradeOpenAnswer::dispatchSync($answer->id);

    expect($answer->refresh()->grading_status)->toBe(AnswerGradingStatus::Final)->and($answer->feedback)->toBe('Saved.');
});

test('confidence just under the threshold is not rounded up into publishing', function () {
    $attempt = attemptWithPendingText();
    OpenAnswerGrader::fake([grade(2, 0.795)]);

    GradeOpenAnswer::dispatchSync(answerOfType($attempt, 'open_text')->id);

    $answer = answerOfType($attempt, 'open_text');
    expect($answer->grading_status)->toBe(AnswerGradingStatus::NeedsReview)->and((float) $answer->ai_confidence)->toBe(0.79);
});
