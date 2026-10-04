<?php

namespace App\Jobs;

use App\Actions\Grading\ApplyAiGrade;
use App\Actions\Grading\RefreshAttemptScore;
use App\Ai\Agents\OpenAnswerGrader;
use App\Ai\RecordsAiRun;
use App\Ai\Validation\InvalidAiOutput;
use App\Ai\Validation\OpenAnswerResultValidator;
use App\Enums\AiRunPurpose;
use App\Enums\AnswerGradingStatus;
use App\Enums\QuestionType;
use App\Grading\AiGradeResult;
use App\Grading\PublishGate;
use App\Jobs\Middleware\SkipUnlessAnswerPending;
use App\Models\Answer;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * AI-grades one open answer, then publishes it or sends it to review (docs/03-ai.md).
 */
class GradeOpenAnswer implements ShouldQueue
{
    use Queueable;

    /**
     * Real failures allowed (rate-limit releases don't count towards this).
     */
    public int $maxExceptions = 3;

    /**
     * Below the queue's retry_after (360s); the agent itself times out at 150s.
     */
    public int $timeout = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $answerId)
    {
        $this->onQueue('ai');
    }

    /**
     * Generous, because a large class can wait behind the rate limiter for a while;
     * real failures are bounded by $maxExceptions.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SkipUnlessAnswerPending,
            (new WithoutOverlapping("answer-grading:{$this->answerId}"))->expireAfter(240)->releaseAfter(30),
            new RateLimited('openai'),
        ];
    }

    public function handle(RecordsAiRun $runs, OpenAnswerResultValidator $validator, ApplyAiGrade $apply, RefreshAttemptScore $refresh): void
    {
        $answer = Answer::with(['question', 'attempt.participant.assessment'])->find($this->answerId);

        if ($answer === null) {
            return;
        }

        if ($answer->grading_status !== AnswerGradingStatus::Pending) {
            // A previous try may have settled the answer and died before refreshing the attempt.
            $refresh->handle($answer->attempt);

            return;
        }

        // Checkpoint: a retry after a later failure reuses the stored suggestion instead of paying again.
        $result = $answer->ai_score !== null ? $this->storedResult($answer) : $this->askAi($runs, $validator, $answer);

        if ($result === null) {
            return;
        }

        $apply->handle($answer, $result, PublishGate::threshold($answer->attempt->participant->assessment->auto_publish_threshold));
        $refresh->handle($answer->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $this->markFailed(__('The AI service could not grade this answer.'));
    }

    private function askAi(RecordsAiRun $runs, OpenAnswerResultValidator $validator, Answer $answer): ?AiGradeResult
    {
        $question = $answer->question;
        $language = $question->type === QuestionType::OpenCode ? ($question->code_language->value ?? 'php') : null;

        $agent = new OpenAnswerGrader(
            question: $question->body,
            modelAnswer: $question->model_answer,
            rubric: $question->rubric,
            maxMarks: (float) $answer->max_score,
            codeLanguage: $language,
        );

        $response = $runs->run(
            AiRunPurpose::OpenAnswerGrading,
            $answer,
            fn () => $agent->prompt(OpenAnswerGrader::buildPrompt($answer->text_answer, $answer->code_answer, $language)),
        );

        try {
            $result = $validator->validate($response instanceof Arrayable ? $response->toArray() : [], (float) $answer->max_score);
        } catch (InvalidAiOutput $exception) {
            // Never retried: the answer fails closed and waits for the instructor (D-024).
            $this->markFailed(__('The AI returned an unusable grade: :reason', ['reason' => $exception->getMessage()]));

            return null;
        }

        Answer::query()->whereKey($answer->id)->where('grading_status', AnswerGradingStatus::Pending)->update([
            'ai_score' => $result->score,
            'ai_feedback' => $result->feedback,
            'ai_confidence' => ApplyAiGrade::storedConfidence($result->confidence),
            'ai_breakdown' => json_encode($result->breakdown),
            'ai_flags' => json_encode($result->flags),
        ]);

        return $result;
    }

    private function storedResult(Answer $answer): AiGradeResult
    {
        return new AiGradeResult(
            (float) $answer->ai_score,
            (string) $answer->ai_feedback,
            $answer->ai_breakdown ?? [],
            (float) $answer->ai_confidence,
            $answer->ai_flags ?? [],
        );
    }

    private function markFailed(string $error): void
    {
        $answer = Answer::find($this->answerId);

        if ($answer === null) {
            return;
        }

        Answer::query()
            ->whereKey($answer->id)
            ->where('grading_status', AnswerGradingStatus::Pending)
            ->update(['grading_status' => AnswerGradingStatus::Failed, 'grading_error' => $error, 'updated_at' => now()]);

        app(RefreshAttemptScore::class)->handle($answer->attempt);
    }
}
