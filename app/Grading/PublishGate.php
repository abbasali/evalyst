<?php

namespace App\Grading;

/**
 * Decides whether an AI grade is published without review (pure). See docs/03-ai.md.
 */
class PublishGate
{
    public function decide(AiGradeResult $result, float $maxScore, float $threshold): GateDecision
    {
        $reasons = [];

        if ($result->score < 0 || $result->score > $maxScore || trim($result->feedback) === '') {
            $reasons[] = 'invalid_output';
        }

        if ($result->confidence < $threshold) {
            $reasons[] = 'low_confidence';
        }

        foreach ($result->flags as $flag) {
            if ($flag === 'blank_or_minimal' && $result->score == 0) {
                continue;
            }

            $reasons[] = "flag:{$flag}";
        }

        return new GateDecision($reasons === [], $reasons);
    }

    /**
     * Projects publish only if every AI rule is confident enough and nothing was flagged.
     *
     * @param  list<float>  $ruleConfidences
     * @param  list<string>  $flags
     */
    public function decideProject(array $ruleConfidences, array $flags, float $threshold): GateDecision
    {
        $reasons = [];

        foreach ($ruleConfidences as $confidence) {
            if ($confidence < $threshold) {
                $reasons[] = 'low_confidence';
                break;
            }
        }

        foreach ($flags as $flag) {
            $reasons[] = "flag:{$flag}";
        }

        return new GateDecision($reasons === [], $reasons);
    }

    /**
     * The assessment's threshold, else the course-wide default.
     */
    public static function threshold(?string $assessmentThreshold): float
    {
        return $assessmentThreshold !== null ? (float) $assessmentThreshold : (float) config('evalyst.ai.auto_publish_threshold');
    }
}
