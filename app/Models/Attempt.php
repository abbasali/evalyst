<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use Database\Factories\AttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A participant's single quiz attempt. The table exists from M05 (D-018); M06 builds the flow.
 *
 * @property int $id
 * @property int $participant_id
 * @property string $public_id
 * @property AttemptStatus $status
 * @property Carbon $started_at
 * @property Carbon $deadline_at
 * @property Carbon|null $submitted_at
 * @property bool $auto_submitted
 * @property list<int> $question_order
 * @property array<int, list<int>>|null $option_order
 * @property int $furthest_position
 * @property string $resume_token
 * @property Carbon|null $resume_override_until
 * @property string|null $score
 * @property string $max_score
 * @property int $focus_lost_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Participant $participant
 * @property-read Collection<int, Answer> $answers
 */
#[Fillable([
    'participant_id', 'status', 'started_at', 'deadline_at', 'submitted_at', 'auto_submitted', 'question_order',
    'option_order', 'furthest_position', 'resume_token', 'resume_override_until', 'score', 'max_score', 'focus_lost_count',
])]
class Attempt extends Model
{
    /** @use HasFactory<AttemptFactory> */
    use HasFactory, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * @return HasMany<Answer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * @return HasMany<AttemptEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AttemptEvent::class);
    }

    /**
     * @param  Builder<Attempt>  $query
     */
    public function scopeForCourse(Builder $query, Team $team): void
    {
        $query->whereHas('participant.assessment', fn (Builder $query) => $query->where('team_id', $team->id));
    }

    /**
     * In progress but past the deadline plus the save grace: due to be auto-submitted.
     *
     * @param  Builder<Attempt>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('attempts.status', AttemptStatus::InProgress)
            ->where('attempts.deadline_at', '<', now()->subSeconds((int) config('evalyst.quiz.save_grace_seconds')));
    }

    /**
     * In progress with time (or save grace) left: the student may still be answering.
     *
     * @param  Builder<Attempt>  $query
     */
    public function scopeStillWorking(Builder $query): void
    {
        $query->where('attempts.status', AttemptStatus::InProgress)
            ->where('attempts.deadline_at', '>=', now()->subSeconds((int) config('evalyst.quiz.save_grace_seconds')));
    }

    public function isInProgress(): bool
    {
        return $this->status === AttemptStatus::InProgress;
    }

    /**
     * Past the deadline plus the grace window for in-flight saves.
     */
    public function isOverdue(): bool
    {
        return now()->gt($this->deadline_at->addSeconds((int) config('evalyst.quiz.save_grace_seconds')));
    }

    /**
     * The assessment_question ID shown at a 1-based position.
     */
    public function assessmentQuestionIdAt(int $position): ?int
    {
        return $this->question_order[$position - 1] ?? null;
    }

    public function questionCount(): int
    {
        return count($this->question_order);
    }

    public function cookieName(): string
    {
        return 'attempt_'.$this->public_id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resume_override_until' => 'datetime',
            'auto_submitted' => 'boolean',
            'question_order' => 'array',
            'option_order' => 'array',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
        ];
    }
}
