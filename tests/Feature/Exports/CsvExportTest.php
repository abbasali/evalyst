<?php

use App\Enums\SubmissionStatus;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Student;
use App\Models\Submission;
use Symfony\Component\HttpFoundation\StreamedResponse;

test('the quiz export lists every participant with per-question scores', function () {
    [, $team] = actingAsInstructor();
    $attempt = submittedAttempt([], ['team_id' => $team->id]);
    $attempt->answers()->update(['score' => 1, 'grading_status' => 'final']);
    $attempt->update(['score' => 4, 'status' => 'graded']);

    $response = $this->get(route('assessments.export', [$team, $attempt->participant->assessment]));
    $csv = $response->streamedContent();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($csv)->toStartWith("\xEF\xBB\xBF")
        ->and(str_getcsv(explode("\n", $csv)[0])[1])->toBe('name')
        ->and(explode("\n", trim($csv))[1])->toContain(',graded,')->toContain(',1,1,1,1,4');
});

test('the gradebook includes only published scores', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->published()->for($team)->create(['title' => 'Blog', 'results_released_at' => now()]);
    Assessment::factory()->assignment()->published()->for($team)->create(['title' => 'Hidden', 'closes_at' => now()->addYear()]);
    $student = Student::factory()->for($team)->create(['roll_number' => 'R1']);
    $other = Student::factory()->for($team)->create(['roll_number' => 'R2']);
    Submission::factory()->for(Participant::factory()->for($assignment)->for($student))->graded(8)->create();
    Submission::factory()->for(Participant::factory()->for($assignment)->for($other))->create(['status' => SubmissionStatus::NeedsReview, 'score' => 5]);

    $lines = explode("\n", trim($this->get(route('gradebook', $team))->streamedContent()));

    expect(str_getcsv($lines[1]))->toBe(['roll_number', 'name', 'Blog (assignment)', 'Hidden (assignment)', 'total'])
        ->and(str_getcsv($lines[2])[2])->toBe('8')
        ->and(str_getcsv($lines[3])[2])->toBe('');
});

test('scores of unreleased results stay out of the gradebook', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->published()->for($team)->create();
    Submission::factory()->for(Participant::factory()->for($assignment)->for(Student::factory()->for($team)))->graded(8)->create();

    $lines = explode("\n", trim($this->get(route('gradebook', $team))->streamedContent()));

    expect(str_getcsv($lines[2])[2])->toBe('');
});
