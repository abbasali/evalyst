<?php

use App\Http\Controllers\Instructor\QuestionController;
use App\Http\Controllers\Instructor\StudentController;
use App\Http\Controllers\Instructor\StudentImportController;
use App\Http\Controllers\Instructor\TagController;
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

Route::post('questions/bulk', [QuestionController::class, 'bulk'])->name('questions.bulk');
Route::post('questions/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('questions.duplicate');
Route::post('questions/{question}/restore', [QuestionController::class, 'restore'])->withoutScopedBindings()->name('questions.restore');
Route::resource('questions', QuestionController::class);
Route::resource('tags', TagController::class)->only(['store', 'update', 'destroy']);

Route::inertia('quizzes', 'ComingSoon', ['section' => 'Quizzes'])->name('quizzes.index');
Route::inertia('assignments', 'ComingSoon', ['section' => 'Assignments'])->name('assignments.index');
Route::inertia('review', 'ComingSoon', ['section' => 'Review'])->name('review.index');
