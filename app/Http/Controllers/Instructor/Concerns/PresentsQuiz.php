<?php

namespace App\Http\Controllers\Instructor\Concerns;

use App\Actions\Assessments\PublishAssessment;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

/**
 * Props for the quiz shell (header, status, publish checklist, tabs) shared by every quiz tab.
 */
trait PresentsQuiz
{
    /**
     * @return array<string, mixed>
     */
    protected function quizShell(Team $team, Assessment $quiz): array
    {
        $hasAttempts = $quiz->hasAttempts();
        $can = fn (string $ability) => Gate::inspect($ability, $quiz)->allowed();

        return [
            'quiz' => [
                'id' => $quiz->id,
                'public_id' => $quiz->public_id,
                'title' => $quiz->title,
                'state' => $quiz->state(),
                'access_mode' => $quiz->access_mode->value,
                'opens_at' => $quiz->opens_at?->toIso8601String(),
                'closes_at' => $quiz->closes_at->toIso8601String(),
                'duration_minutes' => $quiz->duration_minutes,
                'questions_count' => $quiz->assessmentQuestions()->count(),
                'max_score' => $quiz->maxScore(),
                'participants_count' => $quiz->participants()->count(),
                'has_attempts' => $hasAttempts,
                'can' => [
                    'update' => $can('update'),
                    'edit_questions' => $can('editQuestions'),
                    'publish' => $can('publish'),
                    'unpublish' => $can('unpublish'),
                    'archive' => $can('archive'),
                    'unarchive' => $can('unarchive'),
                    'delete' => $can('delete'),
                ],
            ],
            'checklist' => $quiz->isDraft() ? app(PublishAssessment::class)->checklist($quiz) : [],
        ];
    }
}
