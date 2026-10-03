<?php

namespace App\Actions\Students;

use App\Models\Team;
use Illuminate\Support\Facades\DB;

class ImportStudents
{
    /**
     * Upsert the valid rows of a preview by (course, roll number).
     *
     * @param  list<array{name: string, roll_number: string, email: string|null, status: string}>  $rows
     * @return array{created: int, updated: int, skipped: int}
     */
    public function handle(Team $team, array $rows): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($team, $rows, &$counts) {
            foreach ($rows as $row) {
                if (! in_array($row['status'], ['new', 'update'], true)) {
                    $counts['skipped'] += $row['status'] === 'error' ? 1 : 0;

                    continue;
                }

                $student = $team->students()->updateOrCreate(
                    ['roll_number' => $row['roll_number']],
                    ['name' => $row['name'], 'email' => $row['email']],
                );

                $counts[$student->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return $counts;
    }
}
