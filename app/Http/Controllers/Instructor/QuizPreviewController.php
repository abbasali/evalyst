<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\QuestionOption;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Preview as student": the real quiz screen with answers kept in the browser only.
 * Nothing is written (no participant, attempt or answers) and questions aren't locked.
 */
class QuizPreviewController extends Controller
{
    public function show(Team $currentTeam, Assessment $quiz): Response
    {
        $items = $quiz->assessmentQuestions()->with('question.options')->get();
        $items = $quiz->shuffle_questions ? $items->shuffle() : $items;

        return Inertia::render('student/quiz/Preview', [
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'course' => $currentTeam->name,
                'duration_minutes' => (int) $quiz->duration_minutes,
                'one_way_navigation' => $quiz->one_way_navigation,
                'require_fullscreen' => $quiz->require_fullscreen,
            ],
            'questions' => $items->values()->map(fn (AssessmentQuestion $item) => [
                'key' => $item->id,
                'type' => $item->question->type->value,
                'body' => $item->question->body,
                'code_language' => $item->question->code_language?->value,
                'options' => ($quiz->shuffle_options ? $item->question->options->shuffle() : $item->question->options)
                    ->map(fn (QuestionOption $option) => ['id' => $option->id, 'body' => $option->body])
                    ->values(),
            ]),
            'serverNow' => now()->toIso8601String(),
        ]);
    }
}
