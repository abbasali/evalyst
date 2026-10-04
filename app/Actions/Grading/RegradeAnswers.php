<?php

namespace App\Actions\Grading;

use App\Actions\Audit\RecordAudit;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Grading\ChoiceScorer;
use App\Jobs\GradeOpenAnswer;
use App\Models\Answer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Grade answers again: open answers go back to the AI, choice answers are rescored at once
 * (e.g. after the scoring policy changed). A published grade stays visible to the student
 * until the new grade is settled. Blank and unsubmitted answers are skipped.
 */
class RegradeAnswers
{
    public function __construct(
        private ChoiceScorer $scorer,
        private RecordAudit $audit,
        private RefreshAttemptScore $refresh,
    ) {}

    /**
     * @param  iterable<Answer>  $answers
     * @param  bool  $keepInstructorGrades  Skip open answers an instructor graded by hand (question-wide regrades),
     *                                      so a confident AI result can't silently replace their decision.
     * @return int How many answers were regraded.
     */
    public function handle(User $user, iterable $answers, ?string $note = null, bool $keepInstructorGrades = false): int
    {
        $count = 0;

        foreach ($answers as $answer) {
            $queued = DB::transaction(function () use ($user, $answer, $note, $keepInstructorGrades) {
                $locked = Answer::query()->with(['question.options', 'attempt'])->whereKey($answer->id)->lockForUpdate()->first();

                if ($locked === null || ! $this->canRegrade($locked)) {
                    return null;
                }

                if ($keepInstructorGrades && $locked->graded_by !== null && $locked->question->type->isOpen()) {
                    return null;
                }

                $before = [
                    'score' => $locked->score !== null ? (float) $locked->score : null,
                    'status' => $locked->grading_status->value,
                ];

                $question = $locked->question;

                if ($question->type->isChoice()) {
                    $score = $this->scorer->score(
                        $question->type,
                        $question->scoring_policy,
                        $question->options->where('is_correct', true)->pluck('id')->all(),
                        $locked->selected_option_ids,
                        (float) $locked->max_score,
                    );
                    $locked->update(['score' => $score, 'grading_status' => AnswerGradingStatus::Final, 'published_at' => now()]);
                    $this->audit->handle($user, $locked, 'grade.regrade', ['before' => $before, 'after' => ['score' => $score]], $note);

                    return false;
                }

                $locked->update([
                    'grading_status' => AnswerGradingStatus::Pending,
                    'ai_score' => null,
                    'ai_feedback' => null,
                    'ai_confidence' => null,
                    'ai_breakdown' => null,
                    'ai_flags' => null,
                    'review_reasons' => null,
                    'grading_error' => null,
                ]);
                $this->audit->handle($user, $locked, 'grade.regrade', ['before' => $before, 'after' => ['status' => 'pending']], $note);

                return true;
            });

            if ($queued === null) {
                continue;
            }

            if ($queued) {
                GradeOpenAnswer::dispatch($answer->id);
            }

            $this->refresh->handle($answer->attempt);
            $count++;
        }

        return $count;
    }

    private function canRegrade(Answer $answer): bool
    {
        if ($answer->attempt->status === AttemptStatus::InProgress || $answer->grading_status === AnswerGradingStatus::Ungraded) {
            return false;
        }

        if ($answer->question->type->isChoice()) {
            return true;
        }

        return ! $answer->isBlank() && $answer->grading_status !== AnswerGradingStatus::Pending;
    }
}
