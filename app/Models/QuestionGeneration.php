<?php

namespace App\Models;

use App\Concerns\BelongsToCourse;
use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $prompt
 * @property array<string, int> $type_counts
 * @property string $difficulty
 * @property bool $include_code_output
 * @property list<int>|null $tag_ids
 * @property GenerationStatus $status
 * @property list<array<string, mixed>>|null $drafts
 * @property list<string>|null $warnings
 * @property int $accepted_count
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Team $team
 */
#[Fillable([
    'team_id', 'user_id', 'prompt', 'type_counts', 'difficulty', 'include_code_output',
    'tag_ids', 'status', 'drafts', 'warnings', 'accepted_count', 'error',
])]
class QuestionGeneration extends Model
{
    use BelongsToCourse;

    /**
     * Minutes after which a pending/running generation is considered stuck (worker died).
     */
    public const STALE_AFTER_MINUTES = 15;

    public function isStale(): bool
    {
        return ! $this->status->isFinished()
            && $this->updated_at?->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function requestedTotal(): int
    {
        return (int) array_sum($this->type_counts);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * @return MorphMany<AiRun, $this>
     */
    public function aiRuns(): MorphMany
    {
        return $this->morphMany(AiRun::class, 'subject');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_counts' => 'array',
            'include_code_output' => 'boolean',
            'tag_ids' => 'array',
            'status' => GenerationStatus::class,
            'drafts' => 'array',
            'warnings' => 'array',
        ];
    }
}
