<?php

namespace App\Actions\Students;

use App\Models\Team;
use Illuminate\Support\Facades\DB;

class ImportStudents
{
    /**
     * Upsert the valid rows of a preview by (course, roll number).
     *
     * Rows are re-classified against the database at confirm time, and the write is a
     * single upsert so concurrent imports can't violate the unique index.
     *
     * @param  list<array{name: string, roll_number: string, email: string|null, status: string}>  $rows
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    public function handle(Team $team, array $rows): array
    {
        $valid = collect($rows)->where('status', '!=', 'error')->keyBy('roll_number');
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => count($rows) - $valid->count()];

        DB::transaction(function () use ($team, $valid, &$counts) {
            $existing = $team->students()
                ->whereIn('roll_number', $valid->keys())
                ->get(['roll_number', 'name', 'email'])
                ->keyBy('roll_number');

            $now = now();
            $payload = [];

            foreach ($valid as $roll => $row) {
                $student = $existing->get($roll);

                if ($student && $student->name === $row['name'] && $student->email === $row['email']) {
                    $counts['unchanged']++;

                    continue;
                }

                $counts[$student ? 'updated' : 'created']++;
                $payload[] = [
                    'team_id' => $team->id,
                    'roll_number' => $roll,
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($payload, 500) as $chunk) {
                DB::table('students')->upsert($chunk, ['team_id', 'roll_number'], ['name', 'email', 'updated_at']);
            }
        });

        return $counts;
    }
}
