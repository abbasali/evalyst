<?php

use App\Actions\Assessments\PublishAssessment;
use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Question;
use Illuminate\Validation\ValidationException;

function readyQuiz(array $attributes = []): array
{
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->rosterMode()->create($attributes);
    $quiz->assessmentQuestions()->create(['question_id' => Question::factory()->for($team)->create()->id, 'position' => 1, 'marks' => 1]);
    Participant::factory()->for($quiz)->withCode()->create();

    return [$team, $quiz];
}

it('publishes a ready quiz', function () {
    [$team, $quiz] = readyQuiz();

    $this->post(route('quizzes.publish', [$team, $quiz]))->assertSessionHasNoErrors();

    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Published);
});

dataset('publish problems', [
    'no questions' => [fn (Assessment $quiz) => $quiz->assessmentQuestions()->delete(), 'At least one question'],
    'zero marks' => [fn (Assessment $quiz) => $quiz->assessmentQuestions()->update(['marks' => 0]), 'Every question has marks above 0'],
    'deleted question' => [fn (Assessment $quiz) => $quiz->assessmentQuestions()->first()->question->delete(), 'Remove questions deleted from the bank'],
    'no duration' => [fn (Assessment $quiz) => $quiz->update(['duration_minutes' => null]), 'Duration is set'],
    'closes in the past' => [fn (Assessment $quiz) => $quiz->update(['closes_at' => now()->subMinute()]), 'Closing time is in the future (and after the opening time)'],
    'no roster students' => [fn (Assessment $quiz) => $quiz->participants()->delete(), 'At least one student added'],
]);

it('refuses to publish and lists the problem', function (Closure $break, string $message) {
    [$team, $quiz] = readyQuiz();
    $break($quiz);

    $this->post(route('quizzes.publish', [$team, $quiz]))->assertSessionHasErrors(['publish' => $message]);

    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Draft);
})->with('publish problems');

it('lists every failed check at once', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->create(['duration_minutes' => null]);

    try {
        app(PublishAssessment::class)->handle($quiz);
        $this->fail('Expected the publish to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['publish'])->toBe(['At least one question', 'Duration is set', 'At least one student added']);
    }
});

it('generates the shared code on publish when missing', function () {
    [$team, $quiz] = readyQuiz();
    $quiz->participants()->delete();
    $quiz->update(['access_mode' => 'shared_code', 'shared_code' => null]);

    $this->post(route('quizzes.publish', [$team, $quiz]))->assertSessionHasNoErrors();

    expect($quiz->fresh()->shared_code)->toHaveLength(6);
});

it('unpublishes only while nobody has started', function () {
    [$team, $quiz] = readyQuiz(['status' => AssessmentStatus::Published]);

    $this->post(route('quizzes.unpublish', [$team, $quiz]))->assertRedirect();
    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Draft);

    $quiz->refresh()->update(['status' => AssessmentStatus::Published]);
    Attempt::factory()->for($quiz->participants()->first())->create();

    $this->post(route('quizzes.unpublish', [$team, $quiz]))->assertForbidden();
    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Published);
});

it('archives and unarchives, and archived quizzes are read-only', function () {
    [$team, $quiz] = readyQuiz(['status' => AssessmentStatus::Published]);

    $this->post(route('quizzes.archive', [$team, $quiz]))->assertRedirect();
    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Archived);

    $this->put(route('quizzes.update', [$team, $quiz]), ['title' => 'x'])->assertForbidden();
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [1]])->assertForbidden();

    $this->post(route('quizzes.unarchive', [$team, $quiz]))->assertRedirect();
    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Published);
});

it('shows the publish checklist on draft quiz pages', function () {
    [$team, $quiz] = readyQuiz();

    $this->get(route('quizzes.questions.index', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/Questions')
            ->has('items', 1)
            ->where('quiz.can.publish', true)
            ->where('checklist', fn ($checks) => collect($checks)->every(fn ($check) => $check['ok'])));

    $this->get(route('quizzes.access', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page->component('quizzes/Access')->has('participants', 1));
});

it('keeps a published quiz publishable', function () {
    [$team, $quiz] = readyQuiz(['status' => AssessmentStatus::Published]);

    $this->delete(route('quizzes.questions.destroy', [$team, $quiz, $quiz->assessmentQuestions()->sole()]))
        ->assertSessionHasErrors('question');
    $this->delete(route('quizzes.participants.destroy', [$team, $quiz, $quiz->participants()->sole()]));

    expect($quiz->assessmentQuestions()->count())->toBe(1)
        ->and($quiz->participants()->count())->toBe(1);
});

it('does not archive a quiz while students are taking it', function () {
    [$team, $quiz] = readyQuiz(['status' => AssessmentStatus::Published]);
    Attempt::factory()->for($quiz->participants()->sole())->create();

    $this->post(route('quizzes.archive', [$team, $quiz]))->assertForbidden();

    // Closed, but the attempt may still run its full duration.
    $quiz->update(['closes_at' => now()->subMinute()]);
    $this->post(route('quizzes.archive', [$team, $quiz]))->assertForbidden();

    $quiz->attempts()->update(['attempts.status' => 'submitted', 'submitted_at' => now()]);
    $this->post(route('quizzes.archive', [$team, $quiz]))->assertRedirect();
    expect($quiz->fresh()->status)->toBe(AssessmentStatus::Archived);
});
