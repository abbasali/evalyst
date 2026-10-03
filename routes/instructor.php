<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Instructor routes
|--------------------------------------------------------------------------
|
| Loaded inside the `{current_team}` prefix with EnsureTeamMembership and
| scoped bindings, so a child model must belong to the current course
| (resolved through the Team relation of the same plural name).
|
*/

Route::inertia('question-bank', 'ComingSoon', ['section' => 'Question bank'])->name('questions.index');
Route::inertia('quizzes', 'ComingSoon', ['section' => 'Quizzes'])->name('quizzes.index');
Route::inertia('assignments', 'ComingSoon', ['section' => 'Assignments'])->name('assignments.index');
Route::inertia('students', 'ComingSoon', ['section' => 'Students'])->name('students.index');
Route::inertia('review', 'ComingSoon', ['section' => 'Review'])->name('review.index');
