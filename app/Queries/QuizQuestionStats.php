<?php

namespace App\Queries;

use App\Enums\AttemptStatus;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\QuestionOption;
use Illuminate\Support\Str;

/**
 * How each question of a quiz performed, over graded attempts only.
 */
class QuizQuestionStats
{
    /** Flag a question when fewer or more than this share of students got full marks. */
    public const TOO_HARD = 0.30;

    public const TOO_EASY = 0.95;

    /**
     * @return array{graded: int, questions: list<array<string, mixed>>}
     */
    public function for(Assessment $quiz): array
    {
        $items = $quiz->assessmentQuestions()->with('question.options')->orderBy('position')->get();

        $answers = Answer::query()
            ->whereIn('assessment_question_id', $items->modelKeys())
            ->whereHas('attempt', fn ($query) => $query->where('status', AttemptStatus::Graded))
            ->get(['id', 'assessment_question_id', 'selected_option_ids', 'answered_at', 'score', 'max_score', 'ai_score', 'graded_by'])
            ->groupBy('assessment_question_id');

        $graded = $quiz->attempts()->where('attempts.status', AttemptStatus::Graded)->count();

        return [
            'graded' => $graded,
            'questions' => array_values($items->map(function (AssessmentQuestion $item) use ($answers) {
                $rows = $answers->get($item->id, collect());
                $count = $rows->count();
                $question = $item->question;
                $fullMarks = $rows->filter(fn (Answer $answer) => (float) $answer->score >= (float) $answer->max_score && (float) $answer->max_score > 0)->count();
                $fullShare = $count > 0 ? $fullMarks / $count : null;

                return [
                    'id' => $item->id,
                    'position' => $item->position,
                    'type' => $question->type->value,
                    'excerpt' => Str::limit(Str::squish(str_replace(['`', '**', '#'], '', preg_replace('/```.*?```/s', '[code]', $question->body) ?? '')), 120),
                    'marks' => (float) $item->marks,
                    'graded' => $count,
                    'answered' => $rows->filter(fn (Answer $answer) => $answer->answered_at !== null)->count(),
                    'average_percent' => $count > 0
                        ? round($rows->avg(fn (Answer $answer) => (float) $answer->max_score > 0 ? (float) $answer->score / (float) $answer->max_score * 100 : 0), 2)
                        : null,
                    'full_marks_percent' => $fullShare !== null ? round($fullShare * 100, 2) : null,
                    'flag' => match (true) {
                        $fullShare === null || $count < 5 => null,
                        $fullShare < self::TOO_HARD => 'hard',
                        $fullShare > self::TOO_EASY => 'easy',
                        default => null,
                    },
                    'options' => $question->type->isChoice()
                        ? array_values($question->options->sortBy('position')->map(function (QuestionOption $option) use ($rows, $count) {
                            $picked = $rows->filter(fn (Answer $answer) => in_array($option->id, $answer->selected_option_ids ?? [], true))->count();

                            return [
                                'body' => $option->body,
                                'correct' => $option->is_correct,
                                'count' => $picked,
                                'percent' => $count > 0 ? round($picked / $count * 100, 2) : 0,
                            ];
                        })->all())
                        : null,
                    'overridden' => $question->type->isOpen()
                        ? $rows->filter(fn (Answer $answer) => $answer->graded_by !== null && $answer->ai_score !== null && (float) $answer->ai_score !== (float) $answer->score)->count()
                        : null,
                ];
            })->all()),
        ];
    }
}
