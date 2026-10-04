<?php

namespace App\Actions\Assessments;

use App\Enums\AccessMode;
use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Team;
use App\Models\User;
use App\Support\AccessCode;

class SaveQuiz
{
    /**
     * Create or update a quiz (or an assignment) from the request's validated attributes.
     * Shared-code mode gets its code here.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Team $team, User $user, array $attributes, ?Assessment $quiz = null, AssessmentType $type = AssessmentType::Quiz): Assessment
    {
        $quiz ??= new Assessment([
            'team_id' => $team->id,
            'type' => $type,
            'status' => AssessmentStatus::Draft,
            'created_by' => $user->id,
        ]);

        $quiz->fill($attributes);

        if ($quiz->access_mode === AccessMode::SharedCode) {
            $quiz->shared_code ??= AccessCode::generate(AccessCode::SHARED_LENGTH);
        } else {
            $quiz->shared_code = null;
        }

        $quiz->save();

        return $quiz;
    }
}
