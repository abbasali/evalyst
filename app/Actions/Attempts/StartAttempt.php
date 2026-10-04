<?php

namespace App\Actions\Attempts;

use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\QuestionOption;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartAttempt
{
    /**
     * Start the participant's only attempt. Returns the attempt and the plain resume token
     * (null when an attempt already existed, e.g. a double click).
     *
     * @return array{0: Attempt, 1: string|null}
     */
    public function handle(Participant $participant): array
    {
        try {
            return DB::transaction(function () use ($participant) {
                // Same lock as instructor edits to the question list (LocksQuestionList).
                $assessment = Assessment::query()->whereKey($participant->assessment_id)->lockForUpdate()->firstOrFail();

                if ($existing = $participant->attempt()->first()) {
                    return [$existing, null];
                }

                if (! $assessment->isOpen()) {
                    throw ValidationException::withMessages(['start' => __('This quiz isn\'t open right now.')]);
                }

                /** @var Collection<int, AssessmentQuestion> $items */
                $items = $assessment->assessmentQuestions()->with('question.options')->get();

                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['start' => __('This quiz has no questions yet. Ask your instructor.')]);
                }

                $token = Str::random(64);
                $now = now();
                $order = $assessment->shuffle_questions ? $items->shuffle() : $items;

                $attempt = $participant->attempt()->create([
                    'status' => AttemptStatus::InProgress,
                    'started_at' => $now,
                    'deadline_at' => $now->addMinutes((int) $assessment->duration_minutes)->min($assessment->closes_at),
                    'question_order' => $order->pluck('id')->values()->all(),
                    'option_order' => $assessment->shuffle_options ? $this->shuffledOptions($items) : null,
                    'furthest_position' => 1,
                    'resume_token' => hash('sha256', $token),
                    'max_score' => $items->sum(fn (AssessmentQuestion $item) => (float) $item->marks),
                ]);

                $attempt->answers()->createMany($items->map(fn (AssessmentQuestion $item) => [
                    'assessment_question_id' => $item->id,
                    'question_id' => $item->question_id,
                    'max_score' => $item->marks,
                    'grading_status' => AnswerGradingStatus::Ungraded,
                ])->all());

                return [$attempt, $token];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first.
            return [$participant->attempt()->firstOrFail(), null];
        }
    }

    /**
     * @param  Collection<int, AssessmentQuestion>  $items
     * @return array<int, list<int>>
     */
    private function shuffledOptions(Collection $items): array
    {
        $order = [];

        foreach ($items as $item) {
            if ($item->question->type->isChoice()) {
                $order[$item->id] = array_values($item->question->options->shuffle()->map(fn (QuestionOption $option) => $option->id)->all());
            }
        }

        return $order;
    }
}
