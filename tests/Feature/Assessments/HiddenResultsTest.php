<?php

use App\Enums\ReleaseMode;
use App\Models\Assessment;
use App\Models\Participant;

test('results are never released when releasing to students is off', function () {
    $manual = Assessment::factory()->closed()->resultsHidden()->create(['results_released_at' => now()]);
    $automatic = Assessment::factory()->closed()->resultsHidden()->create(['release_mode' => ReleaseMode::Automatic]);

    expect($manual->resultsReleased())->toBeFalse()
        ->and($automatic->autoReleaseDue())->toBeFalse()
        ->and($automatic->resultsReleased())->toBeFalse();
});

test('a submitted quiz shows the completion message instead of a results link', function () {
    $attempt = submittedAttempt();
    $participant = $attempt->participant;
    $participant->assessment->update(['release_results' => false, 'results_released_at' => now()]);
    studentSession($participant);

    $this->get(route('student.done', $attempt->public_id))
        ->assertInertia(fn ($page) => $page->component('student/quiz/Done')->where('resultsUrl', null));

    $this->get(route('student.results', $participant->public_id))
        ->assertInertia(fn ($page) => $page
            ->component('student/Results')
            ->where('resultsHidden', true)
            ->where('released', false)
            ->where('items', [])
            ->where('total', null));
});

test('an assignment shows the completion message instead of a results link', function () {
    [$submission] = gradableSubmission(['status' => 'final', 'score' => 8, 'published_at' => now()]);
    $participant = $submission->participant;
    $participant->assessment->update(['release_results' => false, 'results_released_at' => now()]);
    studentSession($participant);

    $this->get(route('student.landing', $participant->assessment->public_id))
        ->assertInertia(fn ($page) => $page->component('student/assignment/Show')->where('resultsUrl', null));

    $this->get(route('student.results', $participant->public_id))
        ->assertInertia(fn ($page) => $page->where('resultsHidden', true)->where('grade', null));
});

test('results cannot be released, and the dashboard doesn\'t ask to, when releasing is off', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->closed()->resultsHidden()->for($team)->create();
    Participant::factory()->for($quiz)->create();

    $this->post(route('quizzes.results.release', [$team, $quiz]))->assertRedirect();
    expect($quiz->fresh()->results_released_at)->toBeNull();

    $this->get(route('dashboard', $team))->assertInertia(fn ($page) => $page->has('overview.unreleased', 0));
});
