<?php

namespace App\Jobs;

use App\Ai\Agents\ProjectGrader;
use App\Ai\RecordsAiRun;
use App\Ai\Validation\InvalidAiOutput;
use App\Ai\Validation\ProjectResultValidator;
use App\Data\RepoSnapshot;
use App\Enums\AiRunPurpose;
use App\Enums\RuleKind;
use App\Enums\SubmissionStatus;
use App\Grading\LatePenaltyCalculator;
use App\Grading\PublishGate;
use App\Models\AssignmentRule;
use App\Models\Submission;
use App\Services\GitHub\Checks\CheckRegistry;
use App\Services\GitHub\Exceptions\GitHubRateLimited;
use App\Services\GitHub\Exceptions\RepositoryNotFound;
use App\Services\GitHub\Exceptions\RepositoryPrivate;
use App\Services\GitHub\RepositoryIngestor;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Grades the current submission: read the repo at its SHA through the API (D-007), run the
 * automated checks, judge every AI rule in one ProjectGrader call, apply the late penalty,
 * then publish or send to review through PublishGate.
 */
class GradeSubmission implements ShouldQueue
{
    use Queueable;

    /**
     * Real failures allowed (rate-limit and GitHub releases don't count towards this).
     */
    public int $maxExceptions = 3;

    /**
     * Below the queue's retry_after (360s); the agent itself times out at 240s.
     */
    public int $timeout = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $submissionId)
    {
        $this->onQueue('ai');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("submission-grading:{$this->submissionId}"))->expireAfter(360)->releaseAfter(30),
            new RateLimited('openai'),
        ];
    }

    public function handle(RepositoryIngestor $ingestor, CheckRegistry $checks, RecordsAiRun $runs, ProjectResultValidator $validator, PublishGate $gate): void
    {
        $submission = Submission::with('participant.assessment.team')->find($this->submissionId);

        if ($submission === null || ! $submission->is_current
            || ! in_array($submission->status, [SubmissionStatus::Submitted, SubmissionStatus::Grading], true)) {
            return;
        }

        $submission->update(['status' => SubmissionStatus::Grading, 'error' => null]);
        $assessment = $submission->participant->assessment;

        // Start clean, so a failed run can never leave old AI scores next to new automated ones.
        $submission->ruleResults()->delete();

        try {
            $snapshot = $ingestor->ingest($submission);
        } catch (GitHubRateLimited $exception) {
            // Touched so grading:recover doesn't treat the wait as a lost job.
            $submission->touch();
            $this->release(max(5, (int) now()->diffInSeconds($exception->resetsAt, absolute: false) + 5));

            return;
        } catch (RepositoryNotFound|RepositoryPrivate) {
            $this->markFailed(__('The repository or commit is no longer available on GitHub (deleted or made private).'));

            return;
        }

        $submission->update(['manifest' => $snapshot->toManifest()]);

        /** @var Collection<int, AssignmentRule> $rules */
        $rules = $assessment->rules()->get();
        $automatedLines = $this->runAutomatedChecks($submission, $snapshot, $rules, $checks);
        $aiRules = $rules->filter(fn (AssignmentRule $rule) => $rule->kind === RuleKind::Ai)->values();
        $summary = null;
        $flags = $snapshot->truncated ? ['repo_too_large'] : [];
        $confidences = [];

        // The student may have resubmitted while the repo was being read: don't pay for a stale grade.
        if (! Submission::query()->whereKey($submission->id)->where('is_current', true)->exists()) {
            return;
        }

        if ($aiRules->isNotEmpty()) {
            $agent = new ProjectGrader((string) $assessment->instructions, $aiRules);
            $response = $runs->run(
                AiRunPurpose::ProjectGrading,
                $submission,
                fn () => $agent->prompt(ProjectGrader::buildPrompt($snapshot, $automatedLines)),
            );

            try {
                $result = $validator->validate($response instanceof Arrayable ? $response->toArray() : [], $aiRules);
            } catch (InvalidAiOutput $exception) {
                $this->markFailed(__('The AI returned an unusable grade: :reason', ['reason' => $exception->getMessage()]));

                return;
            }

            foreach ($aiRules as $rule) {
                $row = $result['rules'][$rule->id];
                $confidences[] = $row['confidence'];
                $submission->ruleResults()->updateOrCreate(['assignment_rule_id' => $rule->id], [
                    'score' => $row['score'],
                    'max_score' => $rule->marks,
                    'passed' => null,
                    'reasoning' => $row['reasoning'],
                    'evidence' => $row['evidence'],
                    'ai_confidence' => floor($row['confidence'] * 100) / 100,
                    'overridden_by' => null,
                ]);
            }

            $summary = $result['summary'];
            $flags = [...$flags, ...$result['flags']];
        }

        $raw = round((float) $submission->ruleResults()->whereIn('assignment_rule_id', $rules->modelKeys())->sum('score'), 2);
        $calculator = LatePenaltyCalculator::for($assessment, $submission->participant);
        $minutesLate = $calculator->minutesLate($submission->submitted_at);
        $penalty = $calculator->penalty($minutesLate);
        $decision = $gate->decideProject($confidences, $flags, PublishGate::threshold($assessment->auto_publish_threshold));

        // Only the run that still owns the current submission writes the outcome.
        Submission::query()
            ->whereKey($submission->id)
            ->where('is_current', true)
            ->where('status', SubmissionStatus::Grading)
            ->update([
                'raw_score' => $raw,
                'minutes_late' => $minutesLate,
                'penalty' => $penalty,
                'score' => LatePenaltyCalculator::finalScore($raw, $penalty),
                'max_score' => $rules->sum(fn (AssignmentRule $rule) => (float) $rule->marks),
                'feedback' => $summary,
                'ai_flags' => json_encode($flags),
                'review_reasons' => $decision->publish ? null : json_encode($decision->reasons),
                'status' => $decision->publish ? SubmissionStatus::Final : SubmissionStatus::NeedsReview,
                'published_at' => $decision->publish ? now() : null,
                'graded_by' => null,
                'updated_at' => now(),
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $this->markFailed(__('Grading failed. Retry it from the review inbox.'));
    }

    /**
     * @param  Collection<int, AssignmentRule>  $rules
     * @return list<string> One line per check, given to the AI as context.
     */
    private function runAutomatedChecks(Submission $submission, RepoSnapshot $snapshot, Collection $rules, CheckRegistry $checks): array
    {
        $lines = [];

        foreach ($rules as $rule) {
            if ($rule->kind !== RuleKind::Automated || $rule->check === null) {
                continue;
            }

            $result = $checks->for($rule->check)->check($snapshot, $rule->config ?? [], $submission->participant->assessment, (float) $rule->marks);

            $submission->ruleResults()->updateOrCreate(['assignment_rule_id' => $rule->id], [
                'score' => $result->score,
                'max_score' => $rule->marks,
                'passed' => $result->passed,
                'reasoning' => $result->reasoning,
                'evidence' => $result->evidence,
                'ai_confidence' => null,
                'overridden_by' => null,
            ]);

            $lines[] = "- {$rule->title}: ".($result->passed ? 'passed' : 'not passed').". {$result->reasoning}";
        }

        return $lines;
    }

    private function markFailed(string $error): void
    {
        Submission::query()
            ->whereKey($this->submissionId)
            ->whereIn('status', [SubmissionStatus::Submitted, SubmissionStatus::Grading])
            ->update(['status' => SubmissionStatus::Failed, 'error' => $error, 'updated_at' => now()]);
    }
}
