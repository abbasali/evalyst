<?php

namespace App\Actions\Grading;

use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Grading\ChoiceScorer;
use App\Jobs\GradeOpenAnswer;
use App\Models\Attempt;
use Illuminate\Support\Facades\DB;

class GradeAttempt
{
    public function __construct(private ChoiceScorer $scorer, private RefreshAttemptScore $refresh) {}

    /**
     * Grade a submitted attempt: choice answers and blank open answers are settled now,
     * other open answers are queued for AI grading. Only `ungraded` answers are touched,
     * so running it twice dispatches nothing new.
     */
    public function handle(Attempt $attempt): void
    {
        $pendingIds = DB::transaction(function () use ($attempt) {
            $locked = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status === AttemptStatus::InProgress) {
                return [];
            }

            $answers = $locked->answers()
                ->where('grading_status', AnswerGradingStatus::Ungraded)
                ->with('question.options')
                ->get();

            $pending = [];

            foreach ($answers as $answer) {
                $question = $answer->question;

                if ($question->type->isChoice()) {
                    $answer->update([
                        'score' => $this->scorer->score(
                            $question->type,
                            $question->scoring_policy,
                            $question->options->where('is_correct', true)->pluck('id')->all(),
                            $answer->selected_option_ids,
                            (float) $answer->max_score,
                        ),
                        ...$this->published(),
                    ]);
                } elseif ($answer->isBlank()) {
                    $answer->update(['score' => 0, 'feedback' => __('No answer submitted.'), ...$this->published()]);
                } else {
                    $answer->update(['grading_status' => AnswerGradingStatus::Pending]);
                    $pending[] = $answer->id;
                }
            }

            return $pending;
        });

        foreach ($pendingIds as $answerId) {
            GradeOpenAnswer::dispatch($answerId);
        }

        $this->refresh->handle($attempt);
    }

    /**
     * @return array<string, mixed>
     */
    private function published(): array
    {
        return ['grading_status' => AnswerGradingStatus::Final, 'published_at' => now()];
    }
}
