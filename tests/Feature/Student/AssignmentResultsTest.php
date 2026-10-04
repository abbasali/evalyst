<?php

use App\Enums\SubmissionStatus;

test('an unpublished submission shows nothing but "under review"', function () {
    [$submission] = gradableSubmission();
    $submission->participant->assessment->update(['results_released_at' => now()]);
    $submission->update(['status' => SubmissionStatus::NeedsReview, 'raw_score' => 5, 'score' => 3, 'feedback' => 'Secret feedback']);

    $this->get(route('student.results', $submission->participant->public_id))
        ->assertInertia(fn ($page) => $page->component('student/AssignmentResults')->where('released', true)->where('published', false)->where('grade', null))
        ->assertDontSee('Secret feedback');
});

test('a published grade shows the rules, feedback and penalty', function () {
    [$submission, $rule] = gradableSubmission();
    $submission->participant->assessment->update(['results_released_at' => now()]);
    $submission->ruleResults()->create(['assignment_rule_id' => $rule->id, 'score' => 6, 'max_score' => 8, 'reasoning' => 'Good.']);
    $submission->update(['status' => SubmissionStatus::Final, 'raw_score' => 6, 'penalty' => 2, 'score' => 4, 'minutes_late' => 1440, 'published_at' => now(), 'feedback' => 'Well done.']);

    $this->get(route('student.results', $submission->participant->public_id))
        ->assertInertia(fn ($page) => $page
            ->where('grade.score', 4)
            ->where('grade.penalty', 2)
            ->where('grade.feedback', 'Well done.')
            ->has('grade.rules', 2));
});
