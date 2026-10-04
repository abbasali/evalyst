<?php

use App\Actions\Attempts\StartAttempt;
use App\Actions\Grading\GradeAttempt;
use App\Actions\Grading\RefreshAttemptScore;
use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use Illuminate\Support\Facades\Queue;

test('question stats are computed over graded attempts', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    $first = submittedAttempt([], ['team_id' => $team->id]);
    $quiz = $first->participant->assessment;
    $single = answerOfType($first, 'single_choice');
    $correct = $single->question->options()->where('is_correct', true)->value('id');
    $wrong = $single->question->options()->where('is_correct', false)->value('id');

    $second = Participant::factory()->for($quiz)->withCode()->create();
    [$secondAttempt] = app(StartAttempt::class)->handle($second);
    $secondAttempt->update(['status' => AttemptStatus::Submitted, 'submitted_at' => now()]);

    $single->update(['selected_option_ids' => [$correct], 'answered_at' => now()]);
    answerOfType($secondAttempt, 'single_choice')->update(['selected_option_ids' => [$wrong], 'answered_at' => now()]);

    foreach ([$first, $secondAttempt] as $attempt) {
        app(GradeAttempt::class)->handle($attempt);
        $attempt->answers()->whereIn('grading_status', ['pending'])->update(['grading_status' => 'final', 'score' => 0]);
        app(RefreshAttemptScore::class)->handle($attempt);
    }

    expect(Attempt::where('status', AttemptStatus::Graded)->count())->toBe(2);

    $this->get(route('quizzes.analytics', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/Analytics')
            ->where('graded', 2)
            ->where('questions', function ($questions) {
                $single = collect($questions)->firstWhere('type', 'single_choice');

                return $single['full_marks_percent'] === 50
                    && $single['average_percent'] === 50
                    && collect($single['options'])->sum('count') === 2;
            }));
});

test('another course cannot see a quiz\'s analytics', function () {
    $quiz = Assessment::factory()->create();
    [, $team] = actingAsInstructor();

    $this->get(route('quizzes.analytics', [$team, $quiz]))->assertNotFound();
});
