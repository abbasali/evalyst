<?php

namespace App\Console\Commands;

use App\Actions\Grading\RefreshAttemptScore;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Jobs\GradeAttempt;
use App\Jobs\GradeOpenAnswer;
use App\Models\Answer;
use App\Models\Attempt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('grading:recover')]
#[Description('Re-dispatch grading work that has been stuck for 15+ minutes (lost jobs, deploys)')]
class RecoverGrading extends Command
{
    public const STALE_MINUTES = 15;

    public function handle(RefreshAttemptScore $refresh): int
    {
        $staleBefore = now()->subMinutes(self::STALE_MINUTES);
        $answers = 0;
        $attempts = 0;

        Answer::query()
            ->where('grading_status', AnswerGradingStatus::Pending)
            ->where('updated_at', '<', $staleBefore)
            ->chunkById(100, function ($stale) use (&$answers) {
                foreach ($stale as $answer) {
                    // Touch first so an immediate second run doesn't dispatch it again.
                    $answer->touch();
                    GradeOpenAnswer::dispatch($answer->id);
                    $answers++;
                }
            });

        // Submitted attempts whose GradeAttempt job was lost.
        Attempt::query()
            ->where('status', AttemptStatus::Submitted)
            ->where('updated_at', '<', $staleBefore)
            ->chunkById(100, function ($stale) use (&$attempts) {
                foreach ($stale as $attempt) {
                    $attempt->touch();
                    GradeAttempt::dispatch($attempt);
                    $attempts++;
                }
            });

        // Attempts left in `grading` after their last answer was settled (a worker died in between).
        Attempt::query()
            ->where('status', AttemptStatus::Grading)
            ->where('updated_at', '<', $staleBefore)
            ->whereDoesntHave('answers', fn ($query) => $query->where('grading_status', AnswerGradingStatus::Pending))
            ->chunkById(100, function ($stale) use ($refresh) {
                foreach ($stale as $attempt) {
                    $attempt->touch();
                    $refresh->handle($attempt);
                }
            });

        $this->info("Re-dispatched {$answers} answer(s) and re-queued {$attempts} attempt(s).");

        return self::SUCCESS;
    }
}
