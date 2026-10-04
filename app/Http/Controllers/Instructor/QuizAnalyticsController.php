<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsQuiz;
use App\Models\Assessment;
use App\Models\Team;
use App\Queries\QuizQuestionStats;
use Inertia\Inertia;
use Inertia\Response;

class QuizAnalyticsController extends Controller
{
    use PresentsQuiz;

    public function show(Team $currentTeam, Assessment $quiz, QuizQuestionStats $stats): Response
    {
        return Inertia::render('quizzes/Analytics', [
            ...$this->quizShell($currentTeam, $quiz),
            ...$stats->for($quiz),
        ]);
    }
}
