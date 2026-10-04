<?php

use App\Http\Controllers\Student\AttemptController;
use App\Http\Controllers\Student\AttemptEventController;
use App\Http\Controllers\Student\JoinController;
use App\Http\Controllers\Student\QuizController;
use App\Http\Middleware\EnsureStudentSession;
use App\Models\Participant;
use Illuminate\Http\Request;
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

        // A reset deletes the attempt: send the student back to the landing page to start again.
        $attemptMissing = function (Request $request) {
            $participant = Participant::query()->with('assessment')
                ->whereKey((int) $request->session()->get(EnsureStudentSession::SESSION_KEY))
                ->first();
            $redirect = $participant ? route('student.landing', $participant->assessment->public_id) : route('student.join');

            return $request->expectsJson()
                ? response()->json(['reason' => 'reset', 'redirect' => $redirect], 409)
                : redirect($redirect);
        };

        Route::prefix('attempts/{attempt:public_id}')->group(function () use ($attemptMissing) {
            Route::get('q/{position}', [AttemptController::class, 'show'])->whereNumber('position')->name('question')->missing($attemptMissing);
            Route::put('answers/{position}', [AttemptController::class, 'save'])->whereNumber('position')->middleware('throttle:attempt-saves')->name('answers.save')->missing($attemptMissing);
            Route::post('events', [AttemptEventController::class, 'store'])->middleware('throttle:attempt-events')->name('events.store')->missing($attemptMissing);
            Route::get('review', [AttemptController::class, 'review'])->name('review')->missing($attemptMissing);
            Route::post('submit', [AttemptController::class, 'submit'])->name('submit')->missing($attemptMissing);
            Route::get('done', [AttemptController::class, 'done'])->name('done')->missing($attemptMissing);
        });
    });
});
