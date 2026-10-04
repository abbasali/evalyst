<?php

namespace App\Models;

use App\Concerns\BelongsToCourse;
use App\Enums\AccessMode;
use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\ReleaseMode;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A quiz or an assignment (D-008). Upcoming/open/closed is derived from the dates.
 *
 * @property int $id
 * @property int $team_id
 * @property string $public_id
 * @property AssessmentType $type
 * @property string $title
 * @property string|null $instructions
 * @property AssessmentStatus $status
 * @property AccessMode $access_mode
 * @property string|null $shared_code
 * @property Carbon|null $opens_at
 * @property Carbon $closes_at
 * @property ReleaseMode $release_mode
 * @property Carbon|null $results_released_at
 * @property string|null $auto_publish_threshold
 * @property int|null $created_by
 * @property int|null $duration_minutes
 * @property bool $shuffle_questions
 * @property bool $shuffle_options
 * @property bool $show_answers_after_release
 * @property bool $track_focus
 * @property bool $one_way_navigation
 * @property bool $require_fullscreen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AssessmentQuestion> $assessmentQuestions
 * @property-read Collection<int, Participant> $participants
 * @property-read int|null $assessment_questions_count
 * @property-read string|null $assessment_questions_sum_marks
 * @property-read int|null $participants_count
 * @property-read int|null $attempts_count
 * @property-read int|null $submitted_count
 */
#[Fillable([
    'team_id', 'type', 'title', 'instructions', 'status', 'access_mode', 'shared_code', 'opens_at', 'closes_at',
    'release_mode', 'results_released_at', 'auto_publish_threshold', 'created_by', 'duration_minutes',
    'shuffle_questions', 'shuffle_options', 'show_answers_after_release', 'track_focus', 'one_way_navigation', 'require_fullscreen',
])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use BelongsToCourse, HasFactory, HasUlids, SoftDeletes;

    /**
     * Only `public_id` is a ULID; the primary key stays auto-increment.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return HasMany<AssessmentQuestion, $this>
     */
    public function assessmentQuestions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Question, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'assessment_questions')
            ->withPivot(['id', 'position', 'marks'])
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * @return HasManyThrough<Attempt, Participant, $this>
     */
    public function attempts(): HasManyThrough
    {
        return $this->hasManyThrough(Attempt::class, Participant::class);
    }

    public function isQuiz(): bool
    {
        return $this->type === AssessmentType::Quiz;
    }

    public function isDraft(): bool
    {
        return $this->status === AssessmentStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === AssessmentStatus::Published;
    }

    public function isArchived(): bool
    {
        return $this->status === AssessmentStatus::Archived;
    }

    public function isUpcoming(): bool
    {
        return $this->isPublished() && $this->opens_at !== null && now()->lt($this->opens_at);
    }

    /**
     * A null `opens_at` means open as soon as it is published.
     */
    public function isOpen(): bool
    {
        return $this->isPublished()
            && ($this->opens_at === null || now()->gte($this->opens_at))
            && now()->lt($this->closes_at);
    }

    public function isClosed(): bool
    {
        return $this->isPublished() && now()->gte($this->closes_at);
    }

    /**
     * draft | upcoming | open | closed | archived, for badges and list tabs.
     */
    public function state(): string
    {
        return match (true) {
            $this->isArchived() => 'archived',
            $this->isDraft() => 'draft',
            $this->isUpcoming() => 'upcoming',
            $this->isOpen() => 'open',
            default => 'closed',
        };
    }

    /**
     * Once any student has started, the quiz is protected from edits that would change scores.
     */
    public function hasAttempts(): bool
    {
        return $this->attempts()->exists();
    }

    public function maxScore(): float
    {
        return (float) $this->assessmentQuestions()->sum('marks');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeQuizzes(Builder $query): void
    {
        $query->where('type', AssessmentType::Quiz);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeAssignments(Builder $query): void
    {
        $query->where('type', AssessmentType::Assignment);
    }

    /**
     * Filter by the derived state used for list tabs.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInState(Builder $query, string $state): void
    {
        $now = now();

        match ($state) {
            'draft' => $query->where('status', AssessmentStatus::Draft),
            'archived' => $query->where('status', AssessmentStatus::Archived),
            'upcoming' => $query->where('status', AssessmentStatus::Published)->where('opens_at', '>', $now),
            'open' => $query->where('status', AssessmentStatus::Published)
                ->where(fn (Builder $query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
                ->where('closes_at', '>', $now),
            'closed' => $query->where('status', AssessmentStatus::Published)->where('closes_at', '<=', $now),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AssessmentType::class,
            'status' => AssessmentStatus::class,
            'access_mode' => AccessMode::class,
            'release_mode' => ReleaseMode::class,
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'results_released_at' => 'datetime',
            'auto_publish_threshold' => 'decimal:2',
            'duration_minutes' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'show_answers_after_release' => 'boolean',
            'track_focus' => 'boolean',
            'one_way_navigation' => 'boolean',
            'require_fullscreen' => 'boolean',
        ];
    }
}
