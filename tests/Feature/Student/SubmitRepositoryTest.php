<?php

use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Jobs\GradeSubmission;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use App\Models\Participant;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function openAssignment(array $attributes = []): Participant
{
    $assignment = Assessment::factory()->assignment()->published()->create([
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
        ...$attributes,
    ]);
    AssignmentRule::factory()->for($assignment, 'assessment')->create(['marks' => 10]);

    return Participant::factory()->for($assignment)->withCode()->create();
}

function fakeGitHub(string $repository = 'repository-public'): void
{
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/student/blog' => Http::response(json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$repository}.json")), true)),
        'api.github.com/repos/student/blog/commits/main' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/github/commit-head.json')), true)),
    ]);
}

function submitRepo(Participant $participant, string $url = 'https://github.com/student/blog.git')
{
    studentSession($participant);

    return test()->post(route('student.assignment.submit', $participant->assessment->public_id), ['repo_url' => $url]);
}

test('an on-time submission records the head commit', function () {
    fakeGitHub();
    $participant = openAssignment();

    submitRepo($participant)->assertSessionHasNoErrors();

    $submission = Submission::sole();
    Queue::assertPushed(GradeSubmission::class, fn ($job) => $job->submissionId === $submission->id);
    expect($submission->commit_sha)->toBe('4f2c1a9e8b7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b')
        ->and($submission->repo_url)->toBe('https://github.com/student/blog')
        ->and($submission->minutes_late)->toBe(0)
        ->and((float) $submission->max_score)->toBe(10.0);
});

test('a late submission stores its penalty', function () {
    fakeGitHub();
    $participant = openAssignment(['late_policy' => LatePolicy::Penalty, 'penalty_type' => PenaltyType::PerDay, 'penalty_value' => 2]);
    $this->travelTo($participant->assessment->closes_at->addHours(30));

    submitRepo($participant)->assertSessionHasNoErrors();

    expect(Submission::sole())->minutes_late->toBe(1800)->and((float) Submission::sole()->penalty)->toBe(4.0);
});

test('late work is refused when not allowed', function () {
    fakeGitHub();
    $participant = openAssignment(['late_policy' => LatePolicy::NotAllowed]);
    $this->travelTo($participant->assessment->closes_at->addMinute());

    submitRepo($participant)->assertSessionHasErrors('repo_url');

    expect(Submission::count())->toBe(0);
});

test('a private repository is rejected and nothing is stored', function () {
    fakeGitHub('repository-private');

    submitRepo(openAssignment())->assertSessionHasErrors(['repo_url' => 'Repository must be public and exist. Check the URL and the repository\'s visibility.']);

    expect(Submission::count())->toBe(0);
});

test('a bad url never calls GitHub', function () {
    Http::preventStrayRequests();

    submitRepo(openAssignment(), 'https://gitlab.com/student/blog')->assertSessionHasErrors('repo_url');
});

test('resubmitting keeps history with only the newest current', function () {
    fakeGitHub();
    $participant = openAssignment();

    submitRepo($participant);
    submitRepo($participant);

    expect(Submission::count())->toBe(2)
        ->and(Submission::where('is_current', true)->count())->toBe(1);
});

test('the student page shows the assignment, not the quiz landing', function () {
    $participant = openAssignment();
    studentSession($participant);

    $this->get(route('student.landing', $participant->assessment->public_id))
        ->assertInertia(fn ($page) => $page->component('student/assignment/Show')->where('canSubmit.allowed', true)->has('assignment.rules', 1));
});
