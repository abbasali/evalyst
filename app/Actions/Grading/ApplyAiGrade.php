<?php

namespace App\Actions\Grading;

use App\Enums\AnswerGradingStatus;
use App\Grading\AiGradeResult;
use App\Grading\PublishGate;
use App\Models\Answer;

class ApplyAiGrade
{
    public function __construct(private PublishGate $gate) {}

    /**
     * Store the AI's suggestion and publish it, or send it to review. Only a `pending`
     * answer is touched, so a grade an instructor already settled is never overwritten.
     */
    public function handle(Answer $answer, AiGradeResult $result, float $threshold): bool
    {
        $decision = $this->gate->decide($result, (float) $answer->max_score, $threshold);

        $suggestion = [
            'ai_score' => $result->score,
            'ai_feedback' => $result->feedback,
            'ai_confidence' => self::storedConfidence($result->confidence),
            'ai_breakdown' => $result->breakdown,
            'ai_flags' => $result->flags,
            'grading_error' => null,
        ];

        $outcome = $decision->publish
            ? [
                'score' => $result->score,
                'feedback' => $result->feedback,
                'grading_status' => AnswerGradingStatus::Final,
                'review_reasons' => null,
                'graded_by' => null,
                'published_at' => now(),
            ]
            // A regraded answer keeps its earlier published score until the instructor decides.
            : [
                'grading_status' => AnswerGradingStatus::NeedsReview,
                'review_reasons' => $decision->reasons,
            ];

        $updated = Answer::query()
            ->whereKey($answer->id)
            ->where('grading_status', AnswerGradingStatus::Pending)
            ->update(array_map(
                fn ($value) => is_array($value) ? json_encode($value) : $value,
                [...$suggestion, ...$outcome, 'updated_at' => now()],
            ));

        return $updated === 1;
    }

    /**
     * Stored with 2 decimals, rounded down so a stored value never looks above the threshold
     * when the raw one was below it (the gate always uses the raw value).
     */
    public static function storedConfidence(float $confidence): float
    {
        return floor($confidence * 100) / 100;
    }
}
