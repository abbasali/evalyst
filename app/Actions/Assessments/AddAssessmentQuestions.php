<?php

namespace App\Actions\Assessments;

use App\Actions\Assessments\Concerns\LocksQuestionList;
use App\Models\Assessment;
use App\Models\Question;

class AddAssessmentQuestions
{
    use LocksQuestionList;

    /**
     * Append course questions at the end with their default marks. Already-added IDs are ignored.
     *
     * @param  list<int>  $questionIds
     * @return int The number of questions added.
     */
    public function handle(Assessment $assessment, array $questionIds): int
    {
        return $this->withQuestionListLock($assessment, function () use ($assessment, $questionIds) {
            $existing = $assessment->assessmentQuestions()->pluck('question_id')->all();
            $position = (int) $assessment->assessmentQuestions()->max('position');

            $questions = Question::query()
                ->where('team_id', $assessment->team_id)
                ->whereKey($questionIds)
                ->whereKeyNot($existing)
                ->get()
                ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true));

            foreach ($questions as $question) {
                $assessment->assessmentQuestions()->create([
                    'question_id' => $question->id,
                    'position' => ++$position,
                    'marks' => $question->default_marks,
                ]);
            }

            return $questions->count();
        });
    }
}
