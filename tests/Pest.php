<?php

use App\Actions\Attempts\StartAttempt;
use App\Enums\AttemptStatus;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureStudentSession;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Log in as an instructor who belongs to a course (a fresh one unless given).
 *
 * @return array{0: User, 1: Team}
 */
function actingAsInstructor(?Team $team = null): array
{
    $user = User::factory()->create();
    $team ??= $user->currentTeam;

    if (! $user->belongsToTeam($team)) {
        $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    }

    $user->switchTeam($team);
    test()->actingAs($user);

    return [$user, $team];
}

/**
 * Put a participant in the session the way JoinAssessment does.
 */
function studentSession(Participant $participant): void
{
    test()->withSession([EnsureStudentSession::SESSION_KEY => $participant->id]);
}

/**
 * An open roster quiz with one question of each type (2 choice, 2 open) and a participant.
 *
 * @return array{0: Assessment, 1: Participant}
 */
function openQuizWithParticipant(array $attributes = []): array
{
    $quiz = Assessment::factory()->open()->rosterMode()->create($attributes);

    foreach ([
        Question::factory()->singleChoice(),
        Question::factory()->multipleChoice(),
        Question::factory()->openText(),
        Question::factory()->openCode(),
    ] as $index => $factory) {
        $quiz->assessmentQuestions()->create([
            'question_id' => $factory->for($quiz->team)->create()->id,
            'position' => $index + 1,
            'marks' => 2,
        ]);
    }

    $participant = Participant::factory()->for($quiz)->withCode()->create();

    return [$quiz, $participant];
}

/**
 * A submitted attempt on openQuizWithParticipant()'s quiz (single, multiple, text, code;
 * 2 marks each), with answers keyed by question type.
 *
 * @param  array<string, array<string, mixed>>  $answers  e.g. ['open_text' => ['text_answer' => '...']]
 */
function submittedAttempt(array $answers = [], array $quizAttributes = []): Attempt
{
    [, $participant] = openQuizWithParticipant($quizAttributes);
    [$attempt] = app(StartAttempt::class)->handle($participant);

    foreach ($attempt->answers()->with('question')->get() as $answer) {
        $answer->update($answers[$answer->question->type->value] ?? []);
    }

    $attempt->update(['status' => AttemptStatus::Submitted, 'submitted_at' => now()]);

    return $attempt->refresh();
}

/**
 * The attempt's answer for a question type.
 */
function answerOfType(Attempt $attempt, string $type): Answer
{
    return $attempt->answers()->whereHas('question', fn ($query) => $query->where('type', $type))->firstOrFail();
}
