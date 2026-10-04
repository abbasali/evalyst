<?php

namespace App\Models;

use App\Concerns\HasAuditLogs;
use App\Enums\LateOverride;
use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A student taking part in one assessment. `public_id` is the results-link token.
 *
 * @property int $id
 * @property int $assessment_id
 * @property int $student_id
 * @property string $public_id
 * @property string|null $access_code
 * @property Carbon|null $joined_at
 * @property Carbon|null $deadline_override_at
 * @property LateOverride|null $late_override
 * @property bool $penalty_waived
 * @property string|null $penalty_override
 * @property string|null $override_note
 * @property-read Submission|null $currentSubmission
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Assessment $assessment
 * @property-read Student $student
 * @property-read Attempt|null $attempt
 */
#[Fillable([
    'assessment_id', 'student_id', 'access_code', 'joined_at', 'deadline_override_at', 'late_override',
    'penalty_waived', 'penalty_override', 'override_note',
])]
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasAuditLogs, HasFactory, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * The course, through the assessment (used by RecordAudit).
     *
     * @return Attribute<int, never>
     */
    protected function teamId(): Attribute
    {
        return Attribute::get(fn () => $this->assessment->team_id);
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return HasOne<Attempt, $this>
     */
    public function attempt(): HasOne
    {
        return $this->hasOne(Attempt::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * The latest submission, the only one that is graded.
     *
     * @return HasOne<Submission, $this>
     */
    public function currentSubmission(): HasOne
    {
        return $this->hasOne(Submission::class)->where('is_current', true);
    }

    public function hasOverrides(): bool
    {
        return $this->deadline_override_at !== null || $this->late_override !== null
            || $this->penalty_waived || $this->penalty_override !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'deadline_override_at' => 'datetime',
            'late_override' => LateOverride::class,
            'penalty_waived' => 'boolean',
            'penalty_override' => 'decimal:2',
        ];
    }
}
