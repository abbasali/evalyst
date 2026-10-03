<?php

use App\Http\Controllers\Instructor\StudentController;
use App\Http\Controllers\Instructor\StudentImportController;
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

Route::post('students/import/preview', [StudentImportController::class, 'preview'])->name('students.import.preview');
Route::post('students/import/confirm', [StudentImportController::class, 'confirm'])->name('students.import.confirm');
Route::resource('students', StudentController::class)->only(['index', 'store', 'update', 'destroy']);

Route::inertia('question-bank', 'ComingSoon', ['section' => 'Question bank'])->name('questions.index');
Route::inertia('quizzes', 'ComingSoon', ['section' => 'Quizzes'])->name('quizzes.index');
Route::inertia('assignments', 'ComingSoon', ['section' => 'Assignments'])->name('assignments.index');
Route::inertia('review', 'ComingSoon', ['section' => 'Review'])->name('review.index');
