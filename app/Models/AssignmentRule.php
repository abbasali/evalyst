<?php

namespace App\Models;

use App\Enums\AutomatedCheck;
use App\Enums\RuleKind;
use Database\Factories\AssignmentRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One gradable criterion of an assignment: an automated check or an AI-judged rule.
 *
 * @property int $id
 * @property int $assessment_id
 * @property RuleKind $kind
 * @property string $title
 * @property string|null $description
 * @property AutomatedCheck|null $check
 * @property array<string, mixed>|null $config
 * @property string $marks
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Assessment $assessment
 */
#[Fillable(['assessment_id', 'kind', 'title', 'description', 'check', 'config', 'marks', 'position'])]
class AssignmentRule extends Model
{
    /** @use HasFactory<AssignmentRuleFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return HasMany<SubmissionRuleResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(SubmissionRuleResult::class);
    }

    public function isAi(): bool
    {
        return $this->kind === RuleKind::Ai;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RuleKind::class,
            'check' => AutomatedCheck::class,
            'config' => 'array',
            'marks' => 'decimal:2',
            'position' => 'integer',
        ];
    }
}
