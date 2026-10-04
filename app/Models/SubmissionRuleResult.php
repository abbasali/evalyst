<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property int $assignment_rule_id
 * @property string $score
 * @property string $max_score
 * @property bool|null $passed
 * @property string|null $reasoning
 * @property list<string>|null $evidence
 * @property string|null $ai_confidence
 * @property int|null $overridden_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AssignmentRule $rule
 * @property-read Submission $submission
 */
#[Fillable(['submission_id', 'assignment_rule_id', 'score', 'max_score', 'passed', 'reasoning', 'evidence', 'ai_confidence', 'overridden_by'])]
class SubmissionRuleResult extends Model
{
    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<AssignmentRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AssignmentRule::class, 'assignment_rule_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'passed' => 'boolean',
            'evidence' => 'array',
            'ai_confidence' => 'decimal:2',
        ];
    }
}
