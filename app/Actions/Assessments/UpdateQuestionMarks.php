<?php

namespace App\Actions\Assessments;

use App\Actions\Assessments\Concerns\LocksQuestionList;
use App\Models\AssessmentQuestion;

class UpdateQuestionMarks
{
    use LocksQuestionList;

    public function handle(AssessmentQuestion $item, float $marks): void
    {
        $this->withQuestionListLock($item->assessment, fn () => $item->update(['marks' => $marks]));
    }
}
