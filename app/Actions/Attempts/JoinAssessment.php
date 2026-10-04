<?php

namespace App\Actions\Attempts;

use App\Enums\AccessMode;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Student;
use App\Support\AccessCode;
use Illuminate\Validation\ValidationException;

class JoinAssessment
{
    /**
     * Resolve a typed code to a participant. For a shared code without a name and roll number yet,
     * the assessment is returned so the form can ask for them.
     *
     * @throws ValidationException with a message under `code`
     */
    public function handle(string $code, ?string $name = null, ?string $rollNumber = null): Participant|Assessment
    {
        $code = static::normalize($code);

        if (strlen($code) === AccessCode::ROSTER_LENGTH) {
            $participant = Participant::query()->where('access_code', $code)->with('assessment')->first();

            if ($participant && $participant->assessment->access_mode === AccessMode::Roster) {
                $this->ensureJoinable($participant->assessment, $participant);

                return $participant;
            }
        }

        $assessment = strlen($code) === AccessCode::SHARED_LENGTH
            ? Assessment::query()->where('shared_code', $code)->where('access_mode', AccessMode::SharedCode)->first()
            : null;

        if (! $assessment) {
            throw static::invalid();
        }

        $rollNumber = $rollNumber !== null ? Student::normalizeRollNumber($rollNumber) : '';

        if (trim((string) $name) === '' || $rollNumber === '') {
            $this->ensureJoinable($assessment);

            return $assessment;
        }

        $student = Student::query()->where('team_id', $assessment->team_id)->where('roll_number', $rollNumber)->first();
        $existing = $student ? $assessment->participants()->where('student_id', $student->id)->first() : null;

        $this->ensureJoinable($assessment, $existing);

        // Keep the roster name if the student already exists (D-011).
        $student ??= Student::query()->createOrFirst(
            ['team_id' => $assessment->team_id, 'roll_number' => $rollNumber],
            ['name' => trim((string) $name)],
        );

        return $existing ?? $assessment->participants()->createOrFirst(['student_id' => $student->id]);
    }

    /**
     * "ab cd-1234" → "ABCD1234".
     */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
    }

    /**
     * The same message for every unknown code, so codes can't be enumerated.
     */
    public static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['code' => __('That code doesn\'t match any quiz. Check it and try again.')]);
    }

    private function ensureJoinable(Assessment $assessment, ?Participant $participant = null): void
    {
        if ($assessment->isDraft()) {
            throw static::invalid();
        }

        $message = match (true) {
            $assessment->isArchived() => __('This quiz is no longer available.'),
            $assessment->isUpcoming() => __('This quiz opens on :time.', [
                'time' => $assessment->opens_at?->setTimezone($assessment->team->timezone)->format('j M Y \a\t g:i A'),
            ]),
            // A student who already started can still come back to see their submission.
            $assessment->isClosed() && ! $participant?->attempt()->exists() => __('This quiz has closed.'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['code' => $message]);
        }
    }
}
