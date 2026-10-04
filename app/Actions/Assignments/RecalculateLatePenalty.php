<?php

namespace App\Actions\Assignments;

use App\Grading\LatePenaltyCalculator;
use App\Models\Submission;

class RecalculateLatePenalty
{
    /**
     * Recompute a submission's lateness, penalty and score from the current assignment
     * settings and participant overrides. A published submission stays published.
     */
    public function handle(Submission $submission): Submission
    {
        $participant = $submission->participant;
        $calculator = LatePenaltyCalculator::for($participant->assessment, $participant);
        $minutesLate = $calculator->minutesLate($submission->submitted_at);
        $penalty = $calculator->penalty($minutesLate);

        $submission->update([
            'minutes_late' => $minutesLate,
            'penalty' => $penalty,
            'score' => $submission->raw_score !== null ? LatePenaltyCalculator::finalScore((float) $submission->raw_score, $penalty) : null,
        ]);

        return $submission;
    }
}
