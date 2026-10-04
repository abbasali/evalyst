<?php

use App\Actions\Attempts\StartAttempt;
use App\Enums\AnswerGradingStatus;
use App\Jobs\GradeOpenAnswer;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Question;
use Illuminate\Support\Facades\Queue;

test('bulk accept publishes reviewable rows and skips failed ones', function () {
    [, $team] = actingAsInstructor();
    $ids = collect(range(1, 3))->map(fn () => answerOfType(attemptNeedingReview(['team_id' => $team->id]), 'open_text')->id);
    $failed = answerOfType(attemptNeedingReview(['team_id' => $team->id], ['grading_status' => AnswerGradingStatus::Failed, 'ai_score' => null]), 'open_text');

    $this->post(route('review.bulk-accept', $team), ['ids' => [...$ids, $failed->id]])->assertRedirect();

    expect(AuditLog::where('action', 'grade.bulk_accept')->count())->toBe(3)
        ->and($failed->refresh()->grading_status)->toBe(AnswerGradingStatus::Failed);
});

test('retrying a failed answer queues one job', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    $answer = answerOfType(attemptNeedingReview(['team_id' => $team->id], ['grading_status' => AnswerGradingStatus::Failed, 'ai_score' => null]), 'open_text');

    $this->post(route('review.answers.regrade', [$team, $answer]))->assertRedirect();

    Queue::assertPushedTimes(GradeOpenAnswer::class, 1);
    expect($answer->refresh()->grading_status)->toBe(AnswerGradingStatus::Pending)
        ->and(AuditLog::sole()->action)->toBe('grade.regrade');
});

test('a question-wide regrade queues every non-blank answer and keeps published scores', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    $published = attemptNeedingReview(['team_id' => $team->id], ['grading_status' => AnswerGradingStatus::Final, 'score' => 2, 'published_at' => now()]);
    $quiz = $published->participant->assessment;
    $item = answerOfType($published, 'open_text')->assessmentQuestion;

    // A second student on the same quiz with a blank answer.
    $other = Participant::factory()->for($quiz)->withCode()->create();
    [$blankAttempt] = app(StartAttempt::class)->handle($other);
    $blankAttempt->update(['status' => 'graded', 'submitted_at' => now()]);
    $blankAttempt->answers()->update(['grading_status' => AnswerGradingStatus::Final, 'score' => 0]);

    $this->post(route('quizzes.questions.regrade', [$team, $quiz, $item]))->assertRedirect();

    Queue::assertPushedTimes(GradeOpenAnswer::class, 1);
    $answer = answerOfType($published, 'open_text');
    expect($answer->grading_status)->toBe(AnswerGradingStatus::Pending)
        ->and((float) $answer->score)->toBe(2.0)
        ->and($answer->published_at)->not->toBeNull();
});

test('editing a locked question\'s rubric is audited', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->openText()->locked()->for($team)->create();

    $this->put(route('questions.update', [$team, $question]), [
        'type' => 'open_text', 'body' => $question->body, 'default_marks' => 2,
        'model_answer' => $question->model_answer, 'rubric' => '- New rubric (2 marks)',
    ])->assertSessionHasNoErrors();

    expect(AuditLog::sole()->action)->toBe('question.rubric_update');
});

test('a question-wide regrade keeps answers an instructor graded by hand', function () {
    Queue::fake();
    [$user, $team] = actingAsInstructor();
    $attempt = attemptNeedingReview(['team_id' => $team->id], ['grading_status' => AnswerGradingStatus::Final, 'score' => 1, 'graded_by' => $user->id, 'published_at' => now()]);
    $answer = answerOfType($attempt, 'open_text');

    $this->post(route('quizzes.questions.regrade', [$team, $attempt->participant->assessment, $answer->assessmentQuestion]))->assertRedirect();

    Queue::assertNothingPushed();
    expect($answer->refresh()->grading_status)->toBe(AnswerGradingStatus::Final);
});
