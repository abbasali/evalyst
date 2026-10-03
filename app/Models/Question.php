<?php

namespace App\Models;

use App\Concerns\BelongsToCourse;
use App\Enums\ChoiceScoringPolicy;
use App\Enums\CodeLanguage;
use App\Enums\Difficulty;
use App\Enums\QuestionSource;
use App\Enums\QuestionType;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property QuestionType $type
 * @property string $body
 * @property CodeLanguage|null $code_language
 * @property string $default_marks
 * @property ChoiceScoringPolicy|null $scoring_policy
 * @property string|null $model_answer
 * @property string|null $rubric
 * @property string|null $explanation
 * @property Difficulty|null $difficulty
 * @property QuestionSource $source
 * @property bool $needs_verification
 * @property int|null $created_by
 * @property Carbon|null $locked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, QuestionOption> $options
 * @property-read Collection<int, Tag> $tags
 */
#[Fillable([
    'team_id', 'type', 'body', 'code_language', 'default_marks', 'scoring_policy', 'model_answer',
    'rubric', 'explanation', 'difficulty', 'source', 'needs_verification', 'created_by',
])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use BelongsToCourse, HasFactory, SoftDeletes;

    /**
     * Fields students see; frozen once the question has been answered (D-009).
     */
    public const STUDENT_FACING_FIELDS = ['type', 'body', 'code_language'];

    /**
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function lock(): void
    {
        if (! $this->isLocked()) {
            $this->forceFill(['locked_at' => now()])->save();
        }
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['difficulty'] ?? null, fn (Builder $query, string $difficulty) => $query->where('difficulty', $difficulty))
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where('source', $source))
            ->when($filters['needs_verification'] ?? false, fn (Builder $query) => $query->where('needs_verification', true))
            ->when($filters['tags'] ?? [], fn (Builder $query, array $tags) => $query->whereHas('tags', fn (Builder $query) => $query->whereIn('tags.id', $tags)))
            ->when($filters['trashed'] ?? false, fn (Builder $query) => $query->onlyTrashed())
            ->when(trim((string) ($filters['q'] ?? '')), fn (Builder $query, string $term) => $query->where(
                'body', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%',
            ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'code_language' => CodeLanguage::class,
            'scoring_policy' => ChoiceScoringPolicy::class,
            'difficulty' => Difficulty::class,
            'source' => QuestionSource::class,
            'default_marks' => 'decimal:2',
            'needs_verification' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }
}
