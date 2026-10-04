<?php

namespace App\Support;

use App\Enums\LateOverride;
use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Models\Assessment;
use App\Models\Participant;

/**
 * The late policy in plain words, as students see it.
 */
class LatePolicySummary
{
    public static function for(Assessment $assessment, ?Participant $participant = null): string
    {
        $timezone = $assessment->team->timezone;
        $format = fn ($time) => $time->copy()->setTimezone($timezone)->format('j M, g:i A');

        if ($participant?->late_override === LateOverride::Block) {
            return __('No late submissions.');
        }

        $penalty = match (true) {
            (bool) $participant?->penalty_waived => self::text('No late penalty applies to you.'),
            $participant?->penalty_override !== null => self::text('If you submit late, :value marks are deducted.', ['value' => self::marks((float) $participant->penalty_override)]),
            $assessment->late_policy === LatePolicy::Penalty => self::penalty($assessment),
            default => null,
        };

        if ($participant?->late_override === LateOverride::Allow) {
            return trim(__('Your instructor is accepting late submissions from you.').' '.$penalty);
        }

        $grace = $assessment->grace_minutes > 0
            ? ' '.trans_choice('There is a :count-minute grace period.|There is a :count-minute grace period.', $assessment->grace_minutes)
            : '';

        // A personal deadline after the cutoff makes the cutoff irrelevant for this student.
        $cutoffApplies = $assessment->hard_cutoff_at !== null
            && ($participant?->deadline_override_at === null || $participant->deadline_override_at->lt($assessment->hard_cutoff_at));
        $cutoff = $cutoffApplies ? ' '.__('No submissions after :time.', ['time' => $format($assessment->hard_cutoff_at)]) : '';

        return match ($assessment->late_policy) {
            LatePolicy::Allowed => ($penalty ?? self::text('Late submissions are accepted without a penalty.')).$cutoff.$grace,
            LatePolicy::Penalty => (string) $penalty.$cutoff.$grace,
            default => __('Late submissions are not accepted.').$grace,
        };
    }

    private static function penalty(Assessment $assessment): string
    {
        $value = self::marks((float) $assessment->penalty_value);
        $cap = $assessment->penalty_cap !== null ? ' '.__('up to :cap marks', ['cap' => self::marks((float) $assessment->penalty_cap)]) : '';

        $sentence = match ($assessment->penalty_type) {
            PenaltyType::PerHour => __('Late submissions lose :value marks for each started hour', ['value' => $value]),
            PenaltyType::PerDay => __('Late submissions lose :value marks for each started day', ['value' => $value]),
            default => __('Late submissions lose :value marks', ['value' => $value]),
        };

        return rtrim($sentence.($cap ? ','.$cap : '')).'.';
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private static function marks(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
