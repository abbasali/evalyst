<?php

namespace App\Actions\Assessments;

use App\Enums\AccessMode;
use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\User;
use App\Support\AccessCode;
use Illuminate\Support\Facades\DB;

class DuplicateAssessment
{
    /**
     * Copy an assessment's settings and its questions (shared, not duplicated) or rules into a
     * new draft. Participants, attempts, submissions, dates and released results stay behind.
     */
    public function handle(User $user, Assessment $assessment): Assessment
    {
        return DB::transaction(function () use ($user, $assessment) {
            $copy = $assessment->replicate([
                'public_id', 'shared_code', 'status', 'opens_at', 'results_released_at', 'hard_cutoff_at', 'created_by',
            ]);

            $copy->forceFill([
                'title' => mb_substr(__('Copy of :title', ['title' => $assessment->title]), 0, 150),
                'status' => AssessmentStatus::Draft,
                'opens_at' => null,
                'closes_at' => now()->addWeek()->startOfHour(),
                'shared_code' => $assessment->access_mode === AccessMode::SharedCode ? AccessCode::generate(AccessCode::SHARED_LENGTH) : null,
                'created_by' => $user->id,
            ])->save();

            // Questions deleted from the bank since are left out of the copy.
            foreach ($assessment->assessmentQuestions()->whereHas('question')->get() as $item) {
                $copy->assessmentQuestions()->create($item->only(['question_id', 'position', 'marks']));
            }

            foreach ($assessment->rules()->get() as $rule) {
                $copy->rules()->create($rule->only(['kind', 'title', 'description', 'check', 'config', 'marks', 'position']));
            }

            return $copy;
        });
    }
}
