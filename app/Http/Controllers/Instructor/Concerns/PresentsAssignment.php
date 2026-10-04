<?php

namespace App\Http\Controllers\Instructor\Concerns;

use App\Actions\Assessments\PublishAssessment;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

/**
 * Props for the assignment shell (header, status, publish checklist, tabs) shared by every tab.
 */
trait PresentsAssignment
{
    /**
     * @return array<string, mixed>
     */
    protected function assignmentShell(Team $team, Assessment $assignment): array
    {
        $can = fn (string $ability) => Gate::inspect($ability, $assignment)->allowed();

        return [
            'assignment' => [
                'id' => $assignment->id,
                'public_id' => $assignment->public_id,
                'title' => $assignment->title,
                'state' => $assignment->state(),
                'access_mode' => $assignment->access_mode->value,
                'opens_at' => $assignment->opens_at?->toIso8601String(),
                'closes_at' => $assignment->closes_at->toIso8601String(),
                'rules_count' => $assignment->rules()->count(),
                'max_score' => $assignment->maxScore(),
                'participants_count' => $assignment->participants()->count(),
                'has_submissions' => $assignment->hasAttempts(),
                'can' => [
                    'update' => $can('update'),
                    'publish' => $can('publish'),
                    'unpublish' => $can('unpublish'),
                    'archive' => $can('archive'),
                    'unarchive' => $can('unarchive'),
                    'delete' => $can('delete'),
                ],
            ],
            'checklist' => $assignment->isDraft() ? app(PublishAssessment::class)->checklist($assignment) : [],
        ];
    }
}
