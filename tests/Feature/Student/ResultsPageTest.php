<?php

use App\Enums\AnswerGradingStatus;
use App\Enums\ReleaseMode;

test('an unknown results link is a 404', function () {
    $this->get('/results/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('nothing is shown before release', function () {
    $attempt = submittedAttempt();
    $attempt->answers()->update(['grading_status' => AnswerGradingStatus::Final, 'score' => 2, 'published_at' => now(), 'feedback' => 'Secret']);

    $this->get(route('student.results', $attempt->participant->public_id))
        ->assertInertia(fn ($page) => $page->component('student/Results')->where('released', false)->has('items', 0)->where('total', null));
});

test('unpublished answers never leak their score or feedback', function () {
    $attempt = attemptNeedingReview(['results_released_at' => now()]);

    $this->get(route('student.results', $attempt->participant->public_id))
        ->assertInertia(fn ($page) => $page
            ->where('released', true)
            ->has('items', 4)
            ->where('total', null)
            ->where('items', fn ($items) => collect($items)->where('published', false)->every(fn ($item) => $item['score'] === null && $item['feedback'] === null)
                && collect($items)->where('published', false)->count() === 1))
        ->assertDontSee('Mostly right.')
        ->assertDontSee('rubric');
});

test('automatic release happens once the quiz closes', function () {
    $attempt = submittedAttempt([], ['release_mode' => ReleaseMode::Automatic]);
    $attempt->answers()->update(['grading_status' => AnswerGradingStatus::Final, 'score' => 1, 'published_at' => now()]);
    $url = route('student.results', $attempt->participant->public_id);

    $this->get($url)->assertInertia(fn ($page) => $page->where('released', false));

    $this->travelTo($attempt->participant->assessment->closes_at->addMinute());

    $this->get($url)->assertInertia(fn ($page) => $page->where('released', true)->where('total', 4));
});
