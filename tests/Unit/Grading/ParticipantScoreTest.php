<?php

use App\Enums\AttemptStatus;
use App\Enums\SubmissionStatus;
use App\Grading\ParticipantScore;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Submission;
use Tests\TestCase;

uses(TestCase::class);

function quizParticipant(AttemptStatus $status, ?float $score, bool $published): Participant
{
    $attempt = new Attempt(['status' => $status, 'score' => $score]);
    $attempt->setRelation('answers', collect([new Answer(['published_at' => $published ? now() : null])]));

    return (new Participant)->setRelation('attempt', $attempt);
}

function assignmentParticipant(SubmissionStatus $status, ?float $score, bool $published): Participant
{
    return (new Participant)->setRelation('currentSubmission', new Submission(['status' => $status, 'score' => $score, 'published_at' => $published ? now() : null]));
}

dataset('participant scores', [
    // [participant, is assignment, graded, published]
    'quiz graded and published' => [fn () => quizParticipant(AttemptStatus::Graded, 4, true), false, 4.0, 4.0],
    'quiz graded, not published' => [fn () => quizParticipant(AttemptStatus::Graded, 4, false), false, 4.0, null],
    'quiz still grading' => [fn () => quizParticipant(AttemptStatus::Grading, 3, true), false, null, null],
    'quiz not started' => [fn () => new Participant, false, null, null],
    'assignment final and published' => [fn () => assignmentParticipant(SubmissionStatus::Final, 8, true), true, 8.0, 8.0],
    'assignment final, not published' => [fn () => assignmentParticipant(SubmissionStatus::Final, 8, false), true, 8.0, null],
    'assignment in review' => [fn () => assignmentParticipant(SubmissionStatus::NeedsReview, 5, true), true, null, null],
    'assignment not submitted' => [fn () => new Participant, true, null, null],
    'no participant' => [fn () => null, false, null, null],
]);

test('participant scores', function (?Participant $participant, bool $isAssignment, ?float $graded, ?float $published) {
    expect(ParticipantScore::graded($participant, $isAssignment))->toBe($graded)
        ->and(ParticipantScore::published($participant, $isAssignment))->toBe($published);
})->with('participant scores');
