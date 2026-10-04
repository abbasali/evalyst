<?php

namespace App\Actions\Assessments;

use App\Actions\Assessments\Concerns\LocksQuestionList;
use App\Models\Assessment;
use Illuminate\Validation\ValidationException;

class ReorderAssessmentQuestions
{
    use LocksQuestionList;

    /**
     * Set positions 1..n from the given assessment_question IDs (must be exactly this assessment's).
     *
     * @param  list<int>  $ids
     */
    public function handle(Assessment $assessment, array $ids): void
    {
        $this->withQuestionListLock($assessment, function () use ($assessment, $ids) {
            $current = $assessment->assessmentQuestions()->pluck('id')->all();

            $sorted = $ids;
            sort($sorted);
            sort($current);

            if ($sorted !== $current) {
                throw ValidationException::withMessages(['ids' => __('The question list changed. Reload the page and try again.')]);
            }

            foreach ($ids as $index => $id) {
                $assessment->assessmentQuestions()->whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }
}
