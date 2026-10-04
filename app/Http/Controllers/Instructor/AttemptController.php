<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AttemptEventType;
use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\AttemptEvent;
use App\Models\AuditLog;
use App\Models\Team;
use App\Support\AnswerPresenter;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One student's attempt: every answer with its grade, the activity timeline and audit trail.
 */
class AttemptController extends Controller
{
    public function show(Team $currentTeam, Attempt $attempt): Response
    {
        $participant = $attempt->participant()->with(['student', 'assessment'])->firstOrFail();
        $answers = AnswerPresenter::inOrder($attempt);
        $logs = AuditLog::query()
            ->with('user:id,name')
            ->where('subject_type', (new Answer)->getMorphClass())
            ->whereIn('subject_id', $answers->pluck('id'))
            ->get()
            ->groupBy('subject_id');

        return Inertia::render('attempts/Show', [
            'quiz' => ['id' => $participant->assessment->id, 'title' => $participant->assessment->title],
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'started_at' => $attempt->started_at->toIso8601String(),
                'deadline_at' => $attempt->deadline_at->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'auto_submitted' => $attempt->auto_submitted,
                'score' => $attempt->score !== null ? (float) $attempt->score : null,
                'max_score' => (float) $attempt->max_score,
            ],
            'answers' => $answers->map(fn (Answer $answer) => [
                ...AnswerPresenter::forInstructor($answer, $attempt),
                'assessment_question_id' => $answer->assessment_question_id,
                'audit' => AnswerPresenter::audit($logs->get($answer->id) ?? new Collection),
            ]),
            'events' => $attempt->events()
                ->whereNot('type', AttemptEventType::FocusReturned)
                ->orderBy('occurred_at')
                ->limit(300)
                ->get()
                ->map(fn (AttemptEvent $event) => [
                    'type' => $event->type->value,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'meta' => $event->meta,
                ]),
            'participantAudit' => AnswerPresenter::audit($participant->auditLogs()->with('user:id,name')->get()),
        ]);
    }
}
