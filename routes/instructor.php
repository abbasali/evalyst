<?php

use App\Http\Controllers\Instructor\AssignmentAccessController;
use App\Http\Controllers\Instructor\AssignmentController;
use App\Http\Controllers\Instructor\AssignmentRuleController;
use App\Http\Controllers\Instructor\AssignmentSubmissionController;
use App\Http\Controllers\Instructor\AttemptController;
use App\Http\Controllers\Instructor\QuestionController;
use App\Http\Controllers\Instructor\QuestionGenerationController;
use App\Http\Controllers\Instructor\QuizAccessController;
use App\Http\Controllers\Instructor\QuizController;
use App\Http\Controllers\Instructor\QuizMonitorController;
use App\Http\Controllers\Instructor\QuizPreviewController;
use App\Http\Controllers\Instructor\QuizQuestionController;
use App\Http\Controllers\Instructor\QuizResultsController;
use App\Http\Controllers\Instructor\QuizStatusController;
use App\Http\Controllers\Instructor\ReviewController;
use App\Http\Controllers\Instructor\StudentController;
use App\Http\Controllers\Instructor\StudentImportController;
use App\Http\Controllers\Instructor\SubmissionReviewController;
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

Route::get('questions/generate', [QuestionGenerationController::class, 'create'])->name('question-generations.create');
Route::post('question-generations', [QuestionGenerationController::class, 'store'])->middleware('throttle:question-generation')->name('question-generations.store');
Route::get('question-generations/{questionGeneration}', [QuestionGenerationController::class, 'show'])->name('question-generations.show');
Route::post('question-generations/{questionGeneration}/accept', [QuestionGenerationController::class, 'accept'])->name('question-generations.accept');
Route::post('question-generations/{questionGeneration}/retry', [QuestionGenerationController::class, 'retry'])->name('question-generations.retry');
Route::patch('questions/{question}/verify', [QuestionController::class, 'verify'])->name('questions.verify');

Route::post('questions/bulk', [QuestionController::class, 'bulk'])->name('questions.bulk');
Route::post('questions/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('questions.duplicate');
Route::post('questions/{question}/restore', [QuestionController::class, 'restore'])->withTrashed()->name('questions.restore');
Route::resource('questions', QuestionController::class)->withTrashed(['show']);
Route::resource('tags', TagController::class)->only(['store', 'update', 'destroy']);

Route::prefix('quizzes/{quiz}')->name('quizzes.')->group(function () {
    Route::get('questions', [QuizQuestionController::class, 'index'])->name('questions.index');
    Route::get('questions/bank', [QuizQuestionController::class, 'bank'])->name('questions.bank');
    Route::post('questions', [QuizQuestionController::class, 'store'])->name('questions.store');
    Route::patch('questions/order', [QuizQuestionController::class, 'order'])->name('questions.order');
    Route::patch('questions/{assessmentQuestion}', [QuizQuestionController::class, 'update'])->name('questions.update');
    Route::delete('questions/{assessmentQuestion}', [QuizQuestionController::class, 'destroy'])->name('questions.destroy');

    Route::get('monitor', [QuizMonitorController::class, 'show'])->name('monitor');
    Route::get('preview', [QuizPreviewController::class, 'show'])->name('preview');
    Route::post('participants/{participant}/allow-resume', [QuizMonitorController::class, 'allowResume'])->name('participants.allow-resume');
    Route::post('participants/{participant}/reset-attempt', [QuizMonitorController::class, 'reset'])->name('participants.reset');
    Route::post('participants/{participant}/force-submit', [QuizMonitorController::class, 'forceSubmit'])->name('participants.force-submit');

    Route::get('results', [QuizResultsController::class, 'show'])->name('results');
    Route::post('results/release', [QuizResultsController::class, 'release'])->name('results.release');
    Route::post('results/unrelease', [QuizResultsController::class, 'unrelease'])->name('results.unrelease');
    Route::post('questions/{assessmentQuestion}/regrade', [ReviewController::class, 'regradeQuestion'])->name('questions.regrade');

    Route::get('access', [QuizAccessController::class, 'show'])->name('access');
    Route::post('participants', [QuizAccessController::class, 'storeParticipants'])->name('participants.store');
    Route::post('participants/{participant}/regenerate-code', [QuizAccessController::class, 'regenerateCode'])->name('participants.regenerate-code');
    Route::delete('participants/{participant}', [QuizAccessController::class, 'destroyParticipant'])->name('participants.destroy');
    Route::post('shared-code/rotate', [QuizAccessController::class, 'rotateSharedCode'])->name('shared-code.rotate');
    Route::get('codes/print', [QuizAccessController::class, 'printCodes'])->name('codes.print');
    Route::get('codes.csv', [QuizAccessController::class, 'downloadCodes'])->name('codes.csv');

    Route::post('publish', [QuizStatusController::class, 'publish'])->name('publish');
    Route::post('unpublish', [QuizStatusController::class, 'unpublish'])->name('unpublish');
    Route::post('archive', [QuizStatusController::class, 'archive'])->name('archive');
    Route::post('unarchive', [QuizStatusController::class, 'unarchive'])->name('unarchive');
});
Route::resource('quizzes', QuizController::class)->except('show');

// `{assignment}` is bound explicitly (AppServiceProvider), so the quiz controllers for access,
// status and release serve assignments too.
Route::prefix('assignments/{assignment}')->name('assignments.')->group(function () {
    Route::get('rules', [AssignmentRuleController::class, 'index'])->name('rules');
    Route::put('rules', [AssignmentRuleController::class, 'update'])->name('rules.update');

    Route::get('access', [AssignmentAccessController::class, 'show'])->name('access');
    Route::post('participants', [QuizAccessController::class, 'storeParticipants'])->name('participants.store');
    Route::post('participants/{participant}/regenerate-code', [QuizAccessController::class, 'regenerateCode'])->name('participants.regenerate-code');
    Route::delete('participants/{participant}', [QuizAccessController::class, 'destroyParticipant'])->name('participants.destroy');
    Route::post('shared-code/rotate', [QuizAccessController::class, 'rotateSharedCode'])->name('shared-code.rotate');
    Route::get('codes/print', [QuizAccessController::class, 'printCodes'])->name('codes.print');
    Route::get('codes.csv', [QuizAccessController::class, 'downloadCodes'])->name('codes.csv');

    Route::get('submissions', [AssignmentSubmissionController::class, 'index'])->name('submissions');
    Route::post('submissions/regrade', [SubmissionReviewController::class, 'regradeAll'])->name('submissions.regrade');
    Route::put('participants/{participant}/overrides', [AssignmentSubmissionController::class, 'overrides'])->name('participants.overrides');
    Route::post('results/release', [QuizResultsController::class, 'release'])->name('results.release');
    Route::post('results/unrelease', [QuizResultsController::class, 'unrelease'])->name('results.unrelease');

    Route::post('publish', [QuizStatusController::class, 'publish'])->name('publish');
    Route::post('unpublish', [QuizStatusController::class, 'unpublish'])->name('unpublish');
    Route::post('archive', [QuizStatusController::class, 'archive'])->name('archive');
    Route::post('unarchive', [QuizStatusController::class, 'unarchive'])->name('unarchive');
});
Route::resource('assignments', AssignmentController::class)->except('show');
Route::get('review', [ReviewController::class, 'index'])->name('review.index');
Route::post('review/bulk-accept', [ReviewController::class, 'bulkAccept'])->name('review.bulk-accept');
Route::get('review/answers/{answer}', [ReviewController::class, 'show'])->name('review.answers.show');
Route::post('review/answers/{answer}/accept', [ReviewController::class, 'accept'])->name('review.answers.accept');
Route::put('review/answers/{answer}', [ReviewController::class, 'update'])->name('review.answers.update');
Route::post('review/answers/{answer}/regrade', [ReviewController::class, 'regrade'])->name('review.answers.regrade');
Route::get('attempts/{attempt}', [AttemptController::class, 'show'])->name('attempts.show');
Route::get('review/submissions/{submission}', [SubmissionReviewController::class, 'show'])->name('review.submissions.show');
Route::put('review/submissions/{submission}', [SubmissionReviewController::class, 'update'])->name('review.submissions.update');
Route::post('review/submissions/{submission}/regrade', [SubmissionReviewController::class, 'regrade'])->name('review.submissions.regrade');
Route::post('questions/{question}/regrade', [QuestionController::class, 'regrade'])->name('questions.regrade');
