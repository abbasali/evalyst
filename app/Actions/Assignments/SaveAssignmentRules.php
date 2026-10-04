<?php

namespace App\Actions\Assignments;

use App\Enums\RuleKind;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveAssignmentRules
{
    /**
     * Sync the ordered rule list: update by id, create new ones, delete the rest. A rule with
     * graded results can't be deleted (it would silently drop students' marks).
     *
     * @param  list<array<string, mixed>>  $rules
     */
    public function handle(Assessment $assignment, array $rules): void
    {
        DB::transaction(function () use ($assignment, $rules) {
            $existing = $assignment->rules()->get()->keyBy('id');
            $keep = collect($rules)->pluck('id')->filter()->map(fn ($id) => (int) $id);

            $removed = $existing->keys()->diff($keep);
            $graded = AssignmentRule::query()->whereIn('id', $removed)->whereHas('results')->pluck('title');

            if ($graded->isNotEmpty()) {
                throw ValidationException::withMessages(['rules' => __('These rules already have grades and can\'t be removed: :titles. Set their marks instead.', ['titles' => $graded->implode(', ')])]);
            }

            AssignmentRule::query()->whereIn('id', $removed)->delete();

            foreach ($rules as $index => $rule) {
                $ai = $rule['kind'] === RuleKind::Ai->value;
                $attributes = [
                    'kind' => $rule['kind'],
                    'title' => $rule['title'],
                    'description' => $rule['description'] ?? null,
                    'check' => $ai ? null : $rule['check'],
                    'config' => $ai ? null : Arr::only((array) ($rule['config'] ?? []), ['min', 'pattern', 'min_ratio', 'glob', 'min_matches']),
                    'marks' => round((float) $rule['marks'], 2),
                    'position' => $index + 1,
                ];

                $model = isset($rule['id']) ? $existing->get((int) $rule['id']) : null;

                $model ? $model->update($attributes) : $assignment->rules()->create($attributes);
            }
        });
    }
}
