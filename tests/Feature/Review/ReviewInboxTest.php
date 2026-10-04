<?php

use App\Enums\AnswerGradingStatus;
use App\Queries\ReviewInboxQuery;

test('the inbox lists only this course\'s items and the badge matches', function () {
    [, $team] = actingAsInstructor();
    $mine = attemptNeedingReview(['team_id' => $team->id]);
    attemptNeedingReview(); // another course

    $this->get(route('review.index', $team))
        ->assertInertia(fn ($page) => $page
            ->component('review/Index')
            ->has('rows.data', 1)
            ->where('rows.data.0.id', answerOfType($mine, 'open_text')->id)
            ->where('counts.needs_review', 1)
            ->where('reviewCount', 1));
});

test('inbox filters narrow the rows', function () {
    [, $team] = actingAsInstructor();
    $lowConfidence = attemptNeedingReview(['team_id' => $team->id]);
    attemptNeedingReview(['team_id' => $team->id], ['grading_status' => AnswerGradingStatus::Failed, 'ai_score' => null, 'review_reasons' => null]);

    $this->get(route('review.index', [$team, 'status' => 'failed']))
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.status', 'failed'));

    $this->get(route('review.index', [$team, 'reason' => 'low_confidence']))
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.id', answerOfType($lowConfidence, 'open_text')->id));

    $this->get(route('review.index', [$team, 'assessment' => $lowConfidence->participant->assessment_id]))
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->has('questions', 4));

    expect(ReviewInboxQuery::count($team))->toBe(2);
});
