<?php

use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Submission;

test('extending a deadline makes a late submission on time', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->published()->for($team)->create([
        'closes_at' => now()->subDays(2), 'late_policy' => LatePolicy::Penalty, 'penalty_type' => PenaltyType::PerDay, 'penalty_value' => 2,
    ]);
    $participant = Participant::factory()->for($assignment)->create();
    $submission = Submission::factory()->for($participant)->graded(8, 2)->create(['submitted_at' => now()->subDay(), 'minutes_late' => 1440]);

    $this->put(route('assignments.participants.overrides', [$team, $assignment, $participant]), [
        'deadline_override_at' => now()->setTimezone($team->refresh()->timezone)->format('Y-m-d\TH:i'),
        'override_note' => 'Medical leave',
    ])->assertSessionHasNoErrors();

    $submission->refresh();
    expect($submission->minutes_late)->toBe(0)
        ->and((float) $submission->penalty)->toBe(0.0)
        ->and((float) $submission->score)->toBe(8.0)
        ->and($submission->published_at)->not->toBeNull();

    $log = AuditLog::sole();
    expect($log->action)->toBe('participant.override')
        ->and($log->note)->toBe('Medical leave')
        ->and($log->changes['before']['score'])->toEqual(6);
});

test('the submissions list shows everyone with commit links', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->published()->for($team)->create();
    $submitted = Participant::factory()->for($assignment)->create();
    Participant::factory()->for($assignment)->create();
    $submission = Submission::factory()->for($submitted)->late(30)->create();

    $this->get(route('assignments.submissions', [$team, $assignment]))
        ->assertInertia(fn ($page) => $page->component('assignments/Submissions')
            ->has('rows', 2)
            ->where('summary.late', 1)
            ->where('rows', fn ($rows) => collect($rows)->pluck('submission.commit_url')->filter()->values()->all() === ["https://github.com/student/blog/tree/{$submission->commit_sha}"]));
});

test('a participant from another assignment in the same course is a 404', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->for($team)->create();
    $other = Participant::factory()->for(Assessment::factory()->assignment()->for($team))->create();

    $this->put(route('assignments.participants.overrides', [$team, $assignment, $other]), ['override_note' => 'x'])->assertNotFound();
    $this->delete(route('assignments.participants.destroy', [$team, $assignment, $other]))->assertNotFound();
});

test('a student who submitted cannot be removed', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->for($team)->create();
    $participant = Participant::factory()->for($assignment)->create();
    Submission::factory()->for($participant)->create();

    $this->delete(route('assignments.participants.destroy', [$team, $assignment, $participant]))->assertForbidden();

    expect(Submission::count())->toBe(1);
});
