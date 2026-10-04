<?php

namespace App\Actions\Assignments;

use App\Actions\Audit\RecordAudit;
use App\Enums\SubmissionStatus;
use App\Jobs\GradeSubmission;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegradeSubmissions
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * Grade current submissions again at the same commit (after a rules change, or a retry).
     * The old grade is unpublished until the new one is settled (D-027).
     *
     * @param  iterable<Submission>  $submissions
     */
    public function handle(User $user, iterable $submissions): int
    {
        $count = 0;

        foreach ($submissions as $submission) {
            $queued = DB::transaction(function () use ($user, $submission) {
                $locked = Submission::query()->whereKey($submission->id)->lockForUpdate()->first();

                if ($locked === null || ! $locked->is_current || in_array($locked->status, [SubmissionStatus::Submitted, SubmissionStatus::Grading], true)) {
                    return false;
                }

                $before = [
                    'status' => $locked->status->value,
                    'score' => $locked->score !== null ? (float) $locked->score : null,
                ];

                $locked->update([
                    'status' => SubmissionStatus::Submitted,
                    'error' => null,
                    'review_reasons' => null,
                    'published_at' => null,
                ]);

                $this->audit->handle($user, $locked, 'submission.regrade', ['before' => $before]);

                return true;
            });

            if ($queued) {
                GradeSubmission::dispatch($submission->id);
                $count++;
            }
        }

        return $count;
    }
}
