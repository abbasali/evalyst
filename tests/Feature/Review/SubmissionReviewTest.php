<?php

use App\Enums\SubmissionStatus;
use App\Jobs\GradeSubmission;
use App\Models\AssignmentRule;
use App\Models\AuditLog;
use App\Models\Submission;
use App\Models\SubmissionRuleResult;
use Illuminate\Support\Facades\Queue;

/**
 * A late (penalty 2) submission in review with one rule scored 4/8.
 *
 * @return array{0: Submission, 1: int}
 */
function submissionInReview($team): array
{
    [$submission, $rule] = gradableSubmission();
    $submission->participant->assessment->update(['team_id' => $team->id]);
    AssignmentRule::where('assessment_id', $submission->participant->assessment_id)->where('id', '!=', $rule->id)->delete();
    $result = $submission->ruleResults()->create(['assignment_rule_id' => $rule->id, 'score' => 4, 'max_score' => 8, 'ai_confidence' => 0.5, 'reasoning' => 'Partly.']);
    $submission->update(['status' => SubmissionStatus::NeedsReview, 'raw_score' => 4, 'penalty' => 2, 'score' => 2, 'max_score' => 8, 'minutes_late' => 1440, 'review_reasons' => ['low_confidence']]);

    return [$submission->refresh(), $result->id];
}

test('submissions waiting for review appear in the inbox', function () {
    [, $team] = actingAsInstructor();
    [$submission] = submissionInReview($team);

    $this->get(route('review.index', $team))
        ->assertInertia(fn ($page) => $page->has('submissions', 1)->where('submissions.0.id', $submission->id)->where('reviewCount', 1));
});

test('overriding a rule recalculates the score with the penalty and is audited', function () {
    [, $team] = actingAsInstructor();
    [$submission, $resultId] = submissionInReview($team);

    $this->put(route('review.submissions.update', [$team, $submission]), [
        'rules' => [$resultId => ['score' => 7, 'reasoning' => 'Good use of Form Requests.']],
        'feedback' => 'Nice work.',
    ])->assertSessionHasNoErrors();

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Final)
        ->and((float) $submission->raw_score)->toBe(7.0)
        ->and((float) $submission->score)->toBe(5.0)
        ->and($submission->published_at)->not->toBeNull()
        ->and($submission->ruleResults()->first()->overridden_by)->not->toBeNull()
        ->and(AuditLog::sole()->action)->toBe('grade.override');
});

test('a rule score above its max is rejected', function () {
    [, $team] = actingAsInstructor();
    [$submission, $resultId] = submissionInReview($team);

    $this->put(route('review.submissions.update', [$team, $submission]), ['rules' => [$resultId => ['score' => 9]]])
        ->assertSessionHasErrors("rules.{$resultId}.score");
});

test('regrading dispatches the job and is audited', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    [$submission] = submissionInReview($team);

    $this->post(route('review.submissions.regrade', [$team, $submission]))->assertRedirect();

    Queue::assertPushed(GradeSubmission::class, fn ($job) => $job->submissionId === $submission->id);
    expect($submission->refresh()->status)->toBe(SubmissionStatus::Submitted)
        ->and(AuditLog::sole()->action)->toBe('submission.regrade');
});

test('another course cannot open the submission', function () {
    [, $other] = actingAsInstructor();
    [$submission] = submissionInReview($other);
    [, $team] = actingAsInstructor();

    $this->get(route('review.submissions.show', [$team, $submission]))->assertNotFound();
});

test('an untouched grade with uneven scores publishes, using current rule marks', function () {
    [, $team] = actingAsInstructor();
    [$submission, $resultId] = submissionInReview($team);
    SubmissionRuleResult::find($resultId)->update(['score' => 3.33]);
    SubmissionRuleResult::find($resultId)->rule->update(['marks' => 6]);

    $this->put(route('review.submissions.update', [$team, $submission]), ['feedback' => ''])->assertSessionHasNoErrors();

    $submission->refresh();
    expect((float) $submission->raw_score)->toBe(3.33)
        ->and((float) $submission->max_score)->toBe(6.0)
        ->and($submission->feedback)->toBeNull()
        ->and((float) SubmissionRuleResult::find($resultId)->max_score)->toBe(6.0);
});

test('a replaced submission cannot be published', function () {
    [, $team] = actingAsInstructor();
    [$submission] = submissionInReview($team);
    $submission->update(['is_current' => false]);

    $this->put(route('review.submissions.update', [$team, $submission]), [])->assertSessionHasErrors('decision');
});
