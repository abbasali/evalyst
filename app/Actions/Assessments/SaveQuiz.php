<?php

namespace App\Actions\Assessments;

use App\Actions\Audit\RecordAudit;
use App\Enums\AccessMode;
use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Team;
use App\Models\User;
use App\Support\AccessCode;

class SaveQuiz
{
    public function __construct(private ReleaseResults $release, private RecordAudit $audit) {}

    /**
     * Create or update a quiz (or an assignment) from the request's validated attributes.
     * Shared-code mode gets its code here. Turning "release results to students" on or off is
     * audit-logged, and turning it off also clears any earlier release, so turning it back on
     * never quietly shows old results again.
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
        $releaseToggled = $quiz->exists && $quiz->isDirty('release_results');

        if ($quiz->access_mode === AccessMode::SharedCode) {
            $quiz->shared_code ??= AccessCode::generate(AccessCode::SHARED_LENGTH);
        } else {
            $quiz->shared_code = null;
        }

        $quiz->save();

        if ($releaseToggled) {
            if (! $quiz->release_results) {
                $this->release->unrelease($user, $quiz);
            }

            $this->audit->handle($user, $quiz, $quiz->release_results ? 'results.enable_release' : 'results.disable_release');
        }

        return $quiz;
    }
}
