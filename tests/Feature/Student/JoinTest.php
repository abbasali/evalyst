<?php

use App\Actions\Attempts\JoinAssessment;
use App\Http\Middleware\EnsureStudentSession;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Student;

it('shows the join page to anyone', function () {
    $this->get(route('student.join'))->assertOk()->assertInertia(fn ($page) => $page->component('student/Join'));
});

it('normalises typed codes', function () {
    expect(JoinAssessment::normalize(' ab cd-ef 12 '))->toBe('ABCDEF12');
});

it('joins with a roster code', function () {
    [$quiz, $participant] = openQuizWithParticipant();

    $this->post(route('student.join.store'), ['code' => strtolower($participant->access_code)])
        ->assertRedirect(route('student.landing', $quiz->public_id))
        ->assertSessionHas(EnsureStudentSession::SESSION_KEY, $participant->id);

    expect($participant->fresh()->joined_at)->not->toBeNull();
});

it('joins with a shared code in two steps and reuses the roll number', function () {
    $quiz = Assessment::factory()->open()->sharedCode()->create();
    $student = Student::factory()->for($quiz->team)->create(['name' => 'Asha Verma', 'roll_number' => 'CS-001']);

    $this->post(route('student.join.store'), ['code' => $quiz->shared_code])
        ->assertRedirect()
        ->assertSessionMissing(EnsureStudentSession::SESSION_KEY);

    $this->post(route('student.join.store'), ['code' => $quiz->shared_code, 'name' => 'Someone Else', 'roll_number' => ' cs-001 '])
        ->assertRedirect(route('student.landing', $quiz->public_id));

    $participant = $quiz->participants()->sole();
    expect($participant->student_id)->toBe($student->id)
        ->and($student->fresh()->name)->toBe('Asha Verma');

    $this->post(route('student.join.store'), ['code' => $quiz->shared_code, 'name' => 'Asha', 'roll_number' => 'CS-001']);
    expect($quiz->participants()->count())->toBe(1);

    $this->post(route('student.join.store'), ['code' => $quiz->shared_code, 'name' => 'New Kid', 'roll_number' => 'CS-777']);
    expect($quiz->team->students()->where('roll_number', 'CS-777')->value('name'))->toBe('New Kid');
});

dataset('unjoinable', [
    'unknown code' => [fn () => 'ACDEFHJK', 'That code doesn\'t match any quiz'],
    'draft quiz' => [fn () => Participant::factory()->for(Assessment::factory())->withCode()->create()->access_code, 'That code doesn\'t match any quiz'],
    'archived' => [fn () => Participant::factory()->for(Assessment::factory()->archived())->withCode()->create()->access_code, 'no longer available'],
    'not open yet' => [fn () => Participant::factory()->for(Assessment::factory()->upcoming())->withCode()->create()->access_code, 'opens on'],
    'closed' => [fn () => Participant::factory()->for(Assessment::factory()->closed())->withCode()->create()->access_code, 'has closed'],
    'roster code length but shared quiz' => [fn () => Assessment::factory()->open()->sharedCode()->create()->shared_code.'AA', 'That code doesn\'t match any quiz'],
]);

it('refuses codes that cannot be used', function (Closure $code, string $message) {
    $this->post(route('student.join.store'), ['code' => $code()])
        ->assertInvalid(['code' => $message])
        ->assertSessionMissing(EnsureStudentSession::SESSION_KEY);
})->with('unjoinable');

it('lets a student who started come back after the quiz closed', function () {
    $participant = Participant::factory()->for(Assessment::factory()->closed())->withCode()->create();
    Attempt::factory()->for($participant)->submitted()->create();

    $this->post(route('student.join.store'), ['code' => $participant->access_code])->assertSessionHasNoErrors();
});

it('throttles join attempts', function () {
    // Per session (10/min) in browsers; tests get a new session per request, so check the per-IP ceiling.
    config(['evalyst.student.join_attempts_per_ip_per_minute' => 10]);

    foreach (range(1, 10) as $ignored) {
        $this->post(route('student.join.store'), ['code' => 'ACDEFHJK'])->assertSessionHasErrors('code');
    }

    $this->post(route('student.join.store'), ['code' => 'ACDEFHJK'])
        ->assertInvalid(['code' => 'Too many tries']);
});

it('keeps students out of other participants\' quizzes', function () {
    [$quiz, $participant] = openQuizWithParticipant();
    $other = Participant::factory()->for(Assessment::factory()->open())->withCode()->create();

    $this->get(route('student.landing', $quiz->public_id))->assertRedirect(route('student.join'));

    studentSession($other);
    $this->get(route('student.landing', $quiz->public_id))->assertForbidden();
});

it('asks again when the roll number is blank after normalising', function () {
    $quiz = Assessment::factory()->open()->sharedCode()->create();

    $this->post(route('student.join.store'), ['code' => $quiz->shared_code, 'name' => 'Asha', 'roll_number' => '  '])
        ->assertInvalid(['roll_number']);
});

it('still accepts an older 8-character roster code', function () {
    $quiz = Assessment::factory()->open()->rosterMode()->create();
    $participant = Participant::factory()->for($quiz)->create(['access_code' => 'ACDEFHJK']);

    $this->post(route('student.join.store'), ['code' => 'acde-fhjk'])
        ->assertRedirect(route('student.landing', $quiz->public_id));

    expect($participant->fresh()->joined_at)->not->toBeNull();
});
