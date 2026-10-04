<?php

namespace App\Actions\Assessments;

use App\Models\Assessment;
use App\Models\Student;
use App\Support\AccessCode;
use Illuminate\Support\Facades\DB;

class AddParticipants
{
    /**
     * Roster mode: give each course student a participant row with an 8-character code.
     * Students already added (or from another course) are skipped.
     *
     * @param  list<int>  $studentIds
     * @return int The number of participants added.
     */
    public function handle(Assessment $assessment, array $studentIds): int
    {
        return DB::transaction(function () use ($assessment, $studentIds) {
            Assessment::query()->whereKey($assessment->id)->lockForUpdate()->first();

            $students = Student::query()
                ->where('team_id', $assessment->team_id)
                ->whereKey($studentIds)
                ->whereDoesntHave('participants', fn ($query) => $query->where('assessment_id', $assessment->id))
                ->get();

            foreach ($students as $student) {
                $assessment->participants()->create([
                    'student_id' => $student->id,
                    'access_code' => AccessCode::generate(AccessCode::ROSTER_LENGTH),
                ]);
            }

            return $students->count();
        });
    }
}
