<?php

namespace App\Models;

use App\Concerns\HasAuditLogs;
use App\Enums\SubmissionStatus;
use App\Services\GitHub\RepoUrl;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A repository submitted for an assignment, pinned to the HEAD commit at submission time.
 *
 * @property int $id
 * @property int $participant_id
 * @property string $public_id
 * @property string $repo_url
 * @property string $repo_owner
 * @property string $repo_name
 * @property string $commit_sha
 * @property string $default_branch
 * @property Carbon $submitted_at
 * @property bool $is_current
 * @property int $minutes_late
 * @property SubmissionStatus $status
 * @property array<string, mixed>|null $manifest
 * @property string|null $raw_score
 * @property string $penalty
 * @property string|null $score
 * @property string $max_score
 * @property string|null $feedback
 * @property list<string>|null $ai_flags
 * @property int|null $graded_by
 * @property Carbon|null $published_at
 * @property string|null $error
 * @property list<string>|null $review_reasons
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $team_id
 * @property-read Participant $participant
 * @property-read Collection<int, SubmissionRuleResult> $ruleResults
 */
#[Fillable([
    'participant_id', 'repo_url', 'repo_owner', 'repo_name', 'commit_sha', 'default_branch', 'submitted_at', 'is_current',
    'minutes_late', 'status', 'manifest', 'raw_score', 'penalty', 'score', 'max_score', 'feedback', 'ai_flags', 'graded_by',
    'published_at', 'error', 'review_reasons',
])]
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasAuditLogs, HasFactory, HasUlids;

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
     * @return HasMany<SubmissionRuleResult, $this>
     */
    public function ruleResults(): HasMany
    {
        return $this->hasMany(SubmissionRuleResult::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * The course, through the participant (used by RecordsAiRun and RecordAudit).
     *
     * @return Attribute<int, never>
     */
    protected function teamId(): Attribute
    {
        return Attribute::get(fn () => $this->participant->assessment->team_id);
    }

    /**
     * @param  Builder<Submission>  $query
     */
    public function scopeForCourse(Builder $query, Team $team): void
    {
        $query->whereHas('participant.assessment', fn (Builder $query) => $query->where('team_id', $team->id));
    }

    public function shortSha(): string
    {
        return substr($this->commit_sha, 0, 7);
    }

    public function commitUrl(): string
    {
        return RepoUrl::commitUrl($this->repo_owner, $this->repo_name, $this->commit_sha);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'is_current' => 'boolean',
            'minutes_late' => 'integer',
            'status' => SubmissionStatus::class,
            'manifest' => 'array',
            'raw_score' => 'decimal:2',
            'penalty' => 'decimal:2',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'ai_flags' => 'array',
            'published_at' => 'datetime',
            'review_reasons' => 'array',
        ];
    }
}
