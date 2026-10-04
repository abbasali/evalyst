<?php

namespace App\Grading;

use App\Enums\LateOverride;
use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Models\Assessment;
use App\Models\Participant;
use Carbon\CarbonInterface;

/**
 * Deadlines, lateness and late penalties for an assignment (pure: reads the models'
 * attributes, never the database). See docs/features/assignments.md.
 */
class LatePenaltyCalculator
{
    public function __construct(private Assessment $assessment, private Participant $participant) {}

    public static function for(Assessment $assessment, Participant $participant): self
    {
        return new self($assessment, $participant);
    }

    public function effectiveDeadline(): CarbonInterface
    {
        return $this->participant->deadline_override_at ?? $this->assessment->closes_at;
    }

    /**
     * Late from this moment on (the deadline plus the grace period).
     */
    public function lateFrom(): CarbonInterface
    {
        return $this->effectiveDeadline()->copy()->addMinutes((int) $this->assessment->grace_minutes);
    }

    public function minutesLate(CarbonInterface $submittedAt): int
    {
        $seconds = $submittedAt->getTimestamp() - $this->lateFrom()->getTimestamp();

        return $seconds > 0 ? (int) ceil($seconds / 60) : 0;
    }

    public function canSubmit(CarbonInterface $now): SubmitDecision
    {
        $assessment = $this->assessment;
        $override = $this->participant->late_override;
        $onTime = $now->lte($this->lateFrom());

        return match (true) {
            ! $assessment->isPublished() || ($assessment->opens_at !== null && $now->lt($assessment->opens_at)) => new SubmitDecision(false, reason: __('Submissions aren\'t open yet.')),
            $override === LateOverride::Block && ! $onTime => new SubmitDecision(false, reason: __('The deadline has passed.')),
            $override === LateOverride::Allow => new SubmitDecision(true, late: ! $onTime),
            $onTime => new SubmitDecision(true),
            $assessment->late_policy === LatePolicy::NotAllowed || $assessment->late_policy === null => new SubmitDecision(false, reason: __('The deadline has passed.')),
            $assessment->hard_cutoff_at !== null && $now->gt($assessment->hard_cutoff_at) => new SubmitDecision(false, reason: __('Late submissions are closed.')),
            default => new SubmitDecision(true, late: true),
        };
    }

    public function penalty(int $minutesLate): float
    {
        $assessment = $this->assessment;

        if ($this->participant->penalty_waived || $minutesLate <= 0) {
            return 0.0;
        }

        // A fixed penalty replaces the calculated one, but only for late work (D-026).
        if ($this->participant->penalty_override !== null) {
            return round((float) $this->participant->penalty_override, 2);
        }

        if ($assessment->late_policy !== LatePolicy::Penalty) {
            return 0.0;
        }

        $value = (float) $assessment->penalty_value;
        $penalty = match ($assessment->penalty_type) {
            PenaltyType::PerHour => ceil($minutesLate / 60) * $value,
            PenaltyType::PerDay => ceil($minutesLate / 1440) * $value,
            default => $value,
        };

        if ($assessment->penalty_cap !== null) {
            $penalty = min($penalty, (float) $assessment->penalty_cap);
        }

        return round($penalty, 2);
    }

    public static function finalScore(float $rawScore, float $penalty): float
    {
        return round(max(0, $rawScore - $penalty), 2);
    }
}
