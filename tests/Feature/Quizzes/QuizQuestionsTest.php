<?php

use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Question;

function quizWithQuestions(int $count = 3): array
{
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->create();
    $questions = Question::factory()->for($team)->count($count)->create(['default_marks' => 2]);

    return [$team, $quiz, $questions];
}

it('adds bank questions at the end with their default marks and ignores duplicates', function () {
    [$team, $quiz, $questions] = quizWithQuestions();

    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$questions[1]->id, $questions[0]->id]])
        ->assertSessionHasNoErrors();
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$questions[0]->id, $questions[2]->id]])
        ->assertSessionHasNoErrors();

    $items = $quiz->assessmentQuestions()->get();
    expect($items->pluck('question_id')->all())->toBe([$questions[1]->id, $questions[0]->id, $questions[2]->id])
        ->and($items->pluck('position')->all())->toBe([1, 2, 3])
        ->and($quiz->maxScore())->toBe(6.0);
});

it('rejects questions from another course or deleted from the bank', function () {
    [$team, $quiz] = quizWithQuestions(0);
    $foreign = Question::factory()->create();
    $deleted = Question::factory()->for($team)->create();
    $deleted->delete();

    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$foreign->id]])
        ->assertSessionHasErrors('question_ids.0');
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$deleted->id]])
        ->assertSessionHasErrors('question_ids.0');

    expect($quiz->assessmentQuestions()->count())->toBe(0);
});

it('reorders, updates marks and removes questions keeping positions continuous', function () {
    [$team, $quiz, $questions] = quizWithQuestions();
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => $questions->pluck('id')->all()]);
    [$first, $second, $third] = $quiz->assessmentQuestions()->get();

    $this->patch(route('quizzes.questions.order', [$team, $quiz]), ['ids' => [$third->id, $first->id, $second->id]])
        ->assertSessionHasNoErrors();
    expect($quiz->assessmentQuestions()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id]);

    $this->patch(route('quizzes.questions.order', [$team, $quiz]), ['ids' => [$third->id, $first->id]])
        ->assertSessionHasErrors('ids');

    $this->patch(route('quizzes.questions.update', [$team, $quiz, $first]), ['marks' => 4.5])->assertSessionHasNoErrors();
    $this->patch(route('quizzes.questions.update', [$team, $quiz, $first]), ['marks' => 0])->assertSessionHasErrors('marks');
    $this->patch(route('quizzes.questions.update', [$team, $quiz, $first]), ['marks' => 1.25])->assertSessionHasErrors('marks');

    $this->delete(route('quizzes.questions.destroy', [$team, $quiz, $third]))->assertRedirect();

    $items = $quiz->assessmentQuestions()->get();
    expect($items->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($items->pluck('position')->all())->toBe([1, 2])
        ->and($quiz->maxScore())->toBe(6.5);
});

it('marks bank questions already in the quiz', function () {
    [$team, $quiz, $questions] = quizWithQuestions(2);
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$questions[0]->id]]);
    Question::factory()->create();

    $this->getJson(route('quizzes.questions.bank', [$team, $quiz]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.added', false)
        ->assertJsonPath('data.1.added', true);
});

it('does not reach another quiz\'s questions', function () {
    [$team, $quiz, $questions] = quizWithQuestions(1);
    $other = Assessment::factory()->for($team)->create();
    $item = $other->assessmentQuestions()->create(['question_id' => $questions[0]->id, 'position' => 1, 'marks' => 1]);

    $this->patch(route('quizzes.questions.update', [$team, $quiz, $item]), ['marks' => 2])->assertNotFound();
    $this->delete(route('quizzes.questions.destroy', [$team, $quiz, $item]))->assertNotFound();
});

it('locks the question list once a student has started', function () {
    [$team, $quiz, $questions] = quizWithQuestions(2);
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$questions[0]->id]]);
    $item = $quiz->assessmentQuestions()->sole();
    Attempt::factory()->for(Participant::factory()->for($quiz))->create();

    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => [$questions[1]->id]])->assertForbidden();
    $this->patch(route('quizzes.questions.order', [$team, $quiz]), ['ids' => [$item->id]])->assertForbidden();
    $this->patch(route('quizzes.questions.update', [$team, $quiz, $item]), ['marks' => 5])->assertForbidden();
    $this->delete(route('quizzes.questions.destroy', [$team, $quiz, $item]))->assertForbidden();

    expect($item->fresh()->marks)->toBe('2.00');
});

it('blocks deleting a bank question used in a published quiz', function () {
    [$team, $quiz, $questions] = quizWithQuestions(2);
    $this->post(route('quizzes.questions.store', [$team, $quiz]), ['question_ids' => $questions->pluck('id')->all()]);
    $quiz->update(['status' => 'published']);

    $this->delete(route('questions.destroy', [$team, $questions[0]]))->assertRedirect();
    $this->post(route('questions.bulk', [$team]), ['action' => 'delete', 'ids' => [$questions[1]->id]]);

    expect($questions[0]->fresh()->trashed())->toBeFalse()
        ->and($questions[1]->fresh()->trashed())->toBeFalse();
});
