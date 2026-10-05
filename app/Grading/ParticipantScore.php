<?php

namespace App\Grading;

use App\Enums\AttemptStatus;
use App\Enums\SubmissionStatus;
use App\Models\Answer;
use App\Models\Participant;

/**
 * A participant's final score for one assessment (pure). Reads the loaded `attempt` (+ `attempt.answers`
 * for published) or `currentSubmission` relations, so eager-load them first.
 */
class ParticipantScore
{
    /**
     * What instructors see: grading is finished (quiz graded, submission final), released or not.
     */
    public static function graded(?Participant $participant, bool $isAssignment): ?float
    {
        if ($participant === null) {
            return null;
        }

        if ($isAssignment) {
            $submission = $participant->currentSubmission;

            return $submission && $submission->status === SubmissionStatus::Final && $submission->score !== null
                ? (float) $submission->score
                : null;
        }

        $attempt = $participant->attempt;

        return $attempt && $attempt->status === AttemptStatus::Graded && $attempt->score !== null
            ? (float) $attempt->score
            : null;
    }

    /**
     * What the student sees: graded and every part published. Release timing is checked by the caller.
     */
    public static function published(?Participant $participant, bool $isAssignment): ?float
    {
        $score = self::graded($participant, $isAssignment);

        if ($score === null) {
            return null;
        }

        $isPublished = $isAssignment
            ? $participant?->currentSubmission?->published_at !== null
            : (bool) $participant?->attempt?->answers->every(fn (Answer $answer) => $answer->published_at !== null);

        return $isPublished ? $score : null;
    }
}
