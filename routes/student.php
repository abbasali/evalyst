<?php

use App\Http\Controllers\Student\AttemptController;
use App\Http\Controllers\Student\JoinController;
use App\Http\Controllers\Student\QuizController;
use App\Http\Middleware\EnsureStudentSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Student routes
|--------------------------------------------------------------------------
|
| No accounts: students join with a code and the session holds their
| participant (EnsureStudentSession). Public URLs use ULIDs only.
|
*/

Route::name('student.')->group(function () {
    Route::get('join', [JoinController::class, 'create'])->name('join');
    Route::post('join', [JoinController::class, 'store'])->middleware('throttle:join')->name('join.store');

    Route::middleware(EnsureStudentSession::class)->group(function () {
        Route::get('a/{assessment:public_id}', [QuizController::class, 'show'])->name('landing');
        Route::post('a/{assessment:public_id}/start', [QuizController::class, 'start'])->name('start');
        Route::post('a/{assessment:public_id}/resume', [QuizController::class, 'resume'])->name('resume');

        Route::prefix('attempts/{attempt:public_id}')->group(function () {
            Route::get('q/{position}', [AttemptController::class, 'show'])->whereNumber('position')->name('question');
            Route::put('answers/{position}', [AttemptController::class, 'save'])->whereNumber('position')->middleware('throttle:attempt-saves')->name('answers.save');
            Route::get('review', [AttemptController::class, 'review'])->name('review');
            Route::post('submit', [AttemptController::class, 'submit'])->name('submit');
            Route::get('done', [AttemptController::class, 'done'])->name('done');
        });
    });
});
