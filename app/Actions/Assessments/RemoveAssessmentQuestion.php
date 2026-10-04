<?php

namespace App\Actions\Assessments;

use App\Actions\Assessments\Concerns\LocksQuestionList;
use App\Models\AssessmentQuestion;
use Illuminate\Validation\ValidationException;

class RemoveAssessmentQuestion
{
    use LocksQuestionList;

    /**
     * Remove a question and renumber the rest 1..n. A published quiz keeps at least one question.
     */
    public function handle(AssessmentQuestion $item): void
    {
        $assessment = $item->assessment;

        $this->withQuestionListLock($assessment, function () use ($assessment, $item) {
            if (! $assessment->isDraft() && $assessment->assessmentQuestions()->count() <= 1) {
                throw ValidationException::withMessages(['question' => __('A published quiz needs at least one question. Move it back to draft first.')]);
            }

            $item->delete();

            foreach ($assessment->assessmentQuestions()->pluck('id') as $index => $id) {
                $assessment->assessmentQuestions()->whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }
}
