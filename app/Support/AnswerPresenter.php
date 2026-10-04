<?php

namespace App\Support;

use App\Enums\AnswerGradingStatus;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Shapes answers for the instructor views (review, attempt detail) and the student results
 * page. The student shape never contains rubrics, model answers or unpublished grades.
 */
class AnswerPresenter
{
    /**
     * The attempt's answers in the order the student saw them.
     *
     * @return Collection<int, Answer>
     */
    public static function inOrder(Attempt $attempt): Collection
    {
        $answers = $attempt->answers()->with(['question.options', 'grader:id,name'])->get()->keyBy('assessment_question_id');

        return collect($attempt->question_order)
            ->filter(fn (int $id) => $answers->has($id))
            ->map(fn (int $id) => $answers[$id])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public static function forInstructor(Answer $answer, Attempt $attempt): array
    {
        $question = $answer->question;

        return [
            'id' => $answer->id,
            'question' => [
                'id' => $question->id,
                'type' => $question->type->value,
                'type_label' => $question->type->label(),
                'body' => $question->body,
                'code_language' => $question->code_language?->value,
                'model_answer' => $question->model_answer,
                'rubric' => $question->rubric,
                'explanation' => $question->explanation,
                'scoring_policy' => $question->scoring_policy?->label(),
            ],
            'options' => self::options($answer, $attempt, true),
            'text_answer' => $answer->text_answer,
            'code_answer' => $answer->code_answer,
            'is_blank' => $question->type->isOpen() ? $answer->isBlank() : empty($answer->selected_option_ids),
            'status' => $answer->grading_status->value,
            'score' => $answer->score !== null ? (float) $answer->score : null,
            'max_score' => (float) $answer->max_score,
            'feedback' => $answer->feedback,
            'published_at' => $answer->published_at?->toIso8601String(),
            'graded_by' => $answer->grader?->name,
            'ai' => $answer->ai_score === null && $answer->grading_error === null ? null : [
                'score' => $answer->ai_score !== null ? (float) $answer->ai_score : null,
                'feedback' => $answer->ai_feedback,
                'confidence' => $answer->ai_confidence !== null ? (float) $answer->ai_confidence : null,
                'breakdown' => $answer->ai_breakdown ?? [],
                'flags' => $answer->ai_flags ?? [],
                'reasons' => $answer->review_reasons ?? [],
                'error' => $answer->grading_error,
            ],
        ];
    }

    /**
     * What the student may see once results are released.
     *
     * @return array<string, mixed>
     */
    public static function forStudent(Answer $answer, Attempt $attempt, bool $showAnswers): array
    {
        $question = $answer->question;
        $published = $answer->published_at !== null && $answer->score !== null;

        return [
            'question' => [
                'type' => $question->type->value,
                'body' => $question->body,
                'code_language' => $question->code_language?->value,
                'explanation' => $showAnswers ? $question->explanation : null,
            ],
            'options' => self::options($answer, $attempt, $showAnswers),
            'text_answer' => $answer->text_answer,
            'code_answer' => $answer->code_answer,
            'published' => $published,
            'score' => $published ? (float) $answer->score : null,
            'max_score' => (float) $answer->max_score,
            'feedback' => $published ? $answer->feedback : null,
        ];
    }

    /**
     * Options in the student's order, marking their picks (and the key when allowed).
     *
     * @return list<array{body: string, selected: bool, correct: bool|null}>
     */
    private static function options(Answer $answer, Attempt $attempt, bool $withKey): array
    {
        if ($answer->question->type->isOpen()) {
            return [];
        }

        $order = $attempt->option_order[$answer->assessment_question_id] ?? null;
        $options = $answer->question->options;

        if ($order !== null) {
            $options = $options->sortBy(fn (QuestionOption $option) => array_search($option->id, $order, true));
        }

        $selected = $answer->selected_option_ids ?? [];

        return array_values($options->map(fn (QuestionOption $option) => [
            'body' => $option->body,
            'selected' => in_array($option->id, $selected, true),
            'correct' => $withKey ? $option->is_correct : null,
        ])->all());
    }

    /**
     * Audit entries, newest first, for the AuditTrail panel.
     *
     * @param  EloquentCollection<int, AuditLog>  $logs
     * @return list<array<string, mixed>>
     */
    public static function audit(EloquentCollection $logs): array
    {
        return array_values($logs->sortByDesc(fn (AuditLog $log) => [$log->created_at?->getTimestamp(), $log->id])->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'user' => $log->user?->name,
            'created_at' => $log->created_at?->toIso8601String(),
            'before' => $log->changes['before'] ?? null,
            'after' => $log->changes['after'] ?? null,
            'note' => $log->note,
        ])->all());
    }

    public static function isWaiting(Answer $answer): bool
    {
        return in_array($answer->grading_status, [AnswerGradingStatus::NeedsReview, AnswerGradingStatus::Failed], true);
    }
}
