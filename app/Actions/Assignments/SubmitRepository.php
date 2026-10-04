<?php

namespace App\Actions\Assignments;

use App\Enums\LateOverride;
use App\Enums\SubmissionStatus;
use App\Grading\LatePenaltyCalculator;
use App\Models\Participant;
use App\Models\Submission;
use App\Services\GitHub\Exceptions\GitHubUnavailable;
use App\Services\GitHub\Exceptions\RepositoryEmpty;
use App\Services\GitHub\Exceptions\RepositoryNotFound;
use App\Services\GitHub\Exceptions\RepositoryPrivate;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\RepoUrl;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitRepository
{
    private GitHubClient $github;

    public function __construct()
    {
        $this->github = GitHubClient::quick();
    }

    /**
     * Record a public repository at its current HEAD commit. The SHA and the submission time
     * always belong together, so nothing is stored if GitHub can't be reached (D-007).
     *
     * @throws ValidationException under `repo_url`
     */
    public function handle(Participant $participant, string $url): Submission
    {
        // The moment the student pressed submit counts, not when GitHub answered.
        $submittedAt = now();
        $repoUrl = RepoUrl::parse($url) ?? throw $this->error(__('Enter a GitHub repository URL like https://github.com/your-name/your-project.'));

        $this->ensureCanSubmit($participant, $submittedAt);

        try {
            $repository = $this->github->repository($repoUrl->owner, $repoUrl->repo);
            $head = $this->github->headCommit($repository->owner, $repository->name, $repository->defaultBranch);
        } catch (RepositoryNotFound|RepositoryPrivate) {
            throw $this->error(__('Repository must be public and exist. Check the URL and the repository\'s visibility.'));
        } catch (RepositoryEmpty) {
            throw $this->error(__('This repository has no commits yet. Push your work, then submit.'));
        } catch (GitHubUnavailable $exception) {
            report($exception);

            throw $this->error(__('GitHub is unavailable right now. Please try again in a minute.'));
        }

        $submission = DB::transaction(function () use ($participant, $repository, $head, $submittedAt) {
            // Serialise double clicks and re-check: time passed while GitHub answered.
            $locked = Participant::query()->with('assessment')->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanSubmit($locked, $submittedAt);

            $assessment = $locked->assessment;
            $calculator = LatePenaltyCalculator::for($assessment, $locked);
            $now = $submittedAt;
            $minutesLate = $calculator->minutesLate($now);

            $locked->submissions()->where('is_current', true)->update(['is_current' => false]);

            return $locked->submissions()->create([
                'repo_url' => "https://github.com/{$repository->owner}/{$repository->name}",
                'repo_owner' => $repository->owner,
                'repo_name' => $repository->name,
                'commit_sha' => $head->sha,
                'default_branch' => $repository->defaultBranch,
                'submitted_at' => $now,
                'is_current' => true,
                'minutes_late' => $minutesLate,
                'status' => SubmissionStatus::Submitted,
                'penalty' => $calculator->penalty($minutesLate),
                'max_score' => $assessment->maxScore(),
            ]);
        });

        return $submission;
    }

    private function ensureCanSubmit(Participant $participant, CarbonInterface $at): void
    {
        $assessment = $participant->assessment;
        $decision = LatePenaltyCalculator::for($assessment, $participant)->canSubmit($at);

        if (! $decision->allowed) {
            throw $this->error((string) $decision->reason);
        }

        if (! $participant->currentSubmission()->exists()) {
            return;
        }

        if (! $assessment->allow_resubmission) {
            throw $this->error(__('You have already submitted. Resubmitting isn\'t allowed for this assignment.'));
        }

        // Resubmitting is for before the deadline, unless the instructor allowed this student.
        if ($decision->late && $participant->late_override !== LateOverride::Allow) {
            throw $this->error(__('The deadline has passed, so you can\'t replace your submission.'));
        }
    }

    private function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['repo_url' => $message]);
    }
}
