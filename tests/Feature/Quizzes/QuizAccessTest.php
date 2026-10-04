<?php

use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Student;
use App\Support\AccessCode;

it('adds roster students with unique 6-character codes', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->rosterMode()->create();
    $students = Student::factory()->for($team)->count(3)->create();
    $foreign = Student::factory()->create();

    $this->post(route('quizzes.participants.store', [$team, $quiz]), ['student_ids' => [$foreign->id]])
        ->assertSessionHasErrors('student_ids.0');

    $this->post(route('quizzes.participants.store', [$team, $quiz]), ['student_ids' => $students->pluck('id')->all()])
        ->assertSessionHasNoErrors();
    $this->post(route('quizzes.participants.store', [$team, $quiz]), ['student_ids' => [$students[0]->id]]);

    $codes = $quiz->participants()->pluck('access_code');
    expect($codes)->toHaveCount(3)
        ->and($codes->unique())->toHaveCount(3)
        ->and($codes->every(fn ($code) => preg_match('/^['.AccessCode::ALPHABET.']{6}$/', $code)))->toBeTrue();
});

it('regenerates a code and blocks removing a student who started', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->rosterMode()->create();
    $waiting = Participant::factory()->for($quiz)->withCode()->create();
    $started = Participant::factory()->for($quiz)->withCode()->create();
    Attempt::factory()->for($started)->create();
    $oldCode = $waiting->access_code;

    $this->post(route('quizzes.participants.regenerate-code', [$team, $quiz, $waiting]))->assertRedirect();
    expect($waiting->fresh()->access_code)->not->toBe($oldCode)->toHaveLength(6)
        ->and(Participant::where('access_code', $oldCode)->exists())->toBeFalse();

    $this->delete(route('quizzes.participants.destroy', [$team, $quiz, $started]))->assertForbidden();
    $this->delete(route('quizzes.participants.destroy', [$team, $quiz, $waiting]))->assertRedirect();

    expect($quiz->participants()->pluck('id')->all())->toBe([$started->id]);
});

it('blocks deleting a student who has taken part', function () {
    [, $team] = actingAsInstructor();
    $participant = Participant::factory()->for(Assessment::factory()->for($team))->create();

    $this->delete(route('students.destroy', [$team, $participant->student]))->assertRedirect();

    expect($participant->student->fresh())->not->toBeNull();
});

it('exports codes as CSV and a printable sheet', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->rosterMode()->create(['title' => 'Week 3']);
    $participant = Participant::factory()->for($quiz)
        ->for(Student::factory()->for($team)->state(['name' => '=Asha', 'roll_number' => '@CS-001']))
        ->withCode()->create();

    $csv = $this->get(route('quizzes.codes.csv', [$team, $quiz]))->assertOk()->streamedContent();
    expect($csv)->toBe("roll_number,name,code\n'@CS-001,'=Asha,{$participant->access_code}\n");

    $this->get(route('quizzes.codes.print', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/CodesPrint')
            ->where('cards.0.access_code', $participant->access_code));
});

it('rotates the shared code', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->sharedCode()->create();
    $old = $quiz->shared_code;

    $this->post(route('quizzes.shared-code.rotate', [$team, $quiz]))->assertRedirect();

    expect($quiz->fresh()->shared_code)->not->toBe($old)->toMatch('/^['.AccessCode::ALPHABET.']{6}$/');
    $this->post(route('quizzes.participants.store', [$team, $quiz]), ['student_ids' => [Student::factory()->for($team)->create()->id]])
        ->assertStatus(422);
});

it('keeps participants inside their quiz and course', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->rosterMode()->create();
    $otherQuiz = Participant::factory()->for(Assessment::factory()->for($team))->withCode()->create();
    $foreign = Participant::factory()->withCode()->create();

    $this->post(route('quizzes.participants.regenerate-code', [$team, $quiz, $otherQuiz]))->assertNotFound();
    $this->delete(route('quizzes.participants.destroy', [$team, $quiz, $otherQuiz]))->assertNotFound();
    $this->get(route('quizzes.codes.csv', [$team, $foreign->assessment]))->assertNotFound();
});
