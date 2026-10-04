<?php

namespace App\Actions\Attempts;

use App\Models\Answer;
use App\Models\Attempt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SaveAnswer
{
    public function __construct(private SubmitAttempt $submit) {}

    /**
     * Autosave one answer. An empty answer counts as unanswered.
     *
     * @param  array{selected_option_ids?: list<int>|null, text_answer?: string|null, code_answer?: string|null, flagged?: bool}  $data
     *
     * @throws ConflictHttpException `expired` when the attempt is over, `closed` for a passed one-way question
     */
    public function handle(Attempt $attempt, Answer $answer, array $data): Answer
    {
        if ($this->submit->expireIfOverdue($attempt)) {
            throw new ConflictHttpException('expired');
        }

        return DB::transaction(function () use ($attempt, $answer, $data) {
            // Locked so a save can't land after a concurrent submit or one-way advance.
            $locked = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isInProgress() || $locked->isOverdue()) {
                throw new ConflictHttpException('expired');
            }

            $assessment = $attempt->participant->assessment;
            $position = (int) array_search($answer->assessment_question_id, $locked->question_order, true) + 1;

            if ($assessment->one_way_navigation && $position < $locked->furthest_position) {
                throw new ConflictHttpException('closed');
            }

            $selected = array_values(array_unique(array_map('intval', $data['selected_option_ids'] ?? [])));
            $text = $this->blankToNull($data['text_answer'] ?? null);
            $code = $this->blankToNull($data['code_answer'] ?? null);
            $isAnswered = $selected !== [] || $text !== null || $code !== null;

            $answer->update([
                'selected_option_ids' => $selected ?: null,
                'text_answer' => $text,
                'code_answer' => $code,
                'flagged' => ! $assessment->one_way_navigation && ($data['flagged'] ?? false),
                'answered_at' => $isAnswered ? now() : null,
            ]);

            // Students must be graded against exactly what they saw (D-009).
            if ($isAnswered) {
                $answer->question->lock();
            }

            return $answer;
        });
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
