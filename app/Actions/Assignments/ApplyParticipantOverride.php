<?php

namespace App\Actions\Assignments;

use App\Actions\Audit\RecordAudit;
use App\Enums\LateOverride;
use App\Models\Participant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ApplyParticipantOverride
{
    public function __construct(private RecordAudit $audit, private RecalculateLatePenalty $recalculate) {}

    /**
     * Change a student's deadline or penalty at any time (before, after or after grading),
     * recalculate their current submission and audit the change.
     *
     * @param  array{deadline_override_at: CarbonInterface|null, late_override: LateOverride|null, penalty_waived: bool, penalty_override: float|null, override_note: string}  $overrides
     */
    public function handle(User $user, Participant $participant, array $overrides): Participant
    {
        return DB::transaction(function () use ($user, $participant, $overrides) {
            // Serialise with SubmitRepository, which locks the same row.
            $participant = Participant::query()->with(['assessment', 'student'])->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            $fields = ['deadline_override_at', 'late_override', 'penalty_waived', 'penalty_override'];
            $before = $this->snapshot($participant, $fields);

            $participant->update($overrides);

            $submission = $participant->currentSubmission()->lockForUpdate()->first();
            $scoreBefore = $submission?->score;

            if ($submission) {
                $submission->setRelation('participant', $participant);
                $this->recalculate->handle($submission);
            }

            $this->audit->handle($user, $participant, 'participant.override', [
                'before' => [...$before, 'score' => $scoreBefore !== null ? (float) $scoreBefore : null],
                'after' => [...$this->snapshot($participant, $fields), 'score' => $submission?->score !== null ? (float) $submission->score : null],
            ], $overrides['override_note']);

            return $participant;
        });
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(Participant $participant, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (string $field) => [$field => match (true) {
            $participant->{$field} instanceof CarbonInterface => $participant->{$field}->toIso8601String(),
            $participant->{$field} instanceof LateOverride => $participant->{$field}->value,
            default => $participant->{$field},
        }])->all();
    }
}
