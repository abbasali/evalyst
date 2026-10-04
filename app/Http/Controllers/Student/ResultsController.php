<?php

namespace App\Http\Controllers\Student;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Participant;
use App\Support\AnswerPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A student's private results link (the participant ULID is the secret; no session needed).
 * Only published grades are ever sent, and only after results are released.
 */
class ResultsController extends Controller
{
    public function show(Participant $participant): Response
    {
        $assessment = $participant->assessment;
        $attempt = $participant->attempt;
        $released = $attempt !== null && $attempt->status !== AttemptStatus::InProgress && $assessment->resultsReleased();

        $answers = $released ? AnswerPresenter::inOrder($attempt) : collect();
        $items = $answers->map(fn (Answer $answer) => AnswerPresenter::forStudent($answer, $attempt, $assessment->show_answers_after_release))->values();
        $allPublished = $released && $items->every(fn (array $item) => $item['published']);

        return Inertia::render('student/Results', [
            'assessment' => [
                'title' => $assessment->title,
                'timezone' => $assessment->team->timezone,
            ],
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'submittedAt' => $attempt?->submitted_at?->toIso8601String(),
            'started' => $attempt !== null,
            'released' => $released,
            'items' => $items,
            'total' => $allPublished ? round($items->sum('score'), 2) : null,
            'maxScore' => $released ? (float) $attempt->max_score : null,
        ]);
    }
}
