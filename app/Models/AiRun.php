<?php

namespace App\Models;

use App\Concerns\BelongsToCourse;
use App\Enums\AiRunPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One AI model call, with tokens and cost. Prompts/responses are not stored (student data).
 *
 * @property int $id
 * @property int $team_id
 * @property AiRunPurpose $purpose
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property int $duration_ms
 * @property bool $succeeded
 * @property string|null $error
 */
#[Fillable([
    'team_id', 'purpose', 'subject_type', 'subject_id', 'provider', 'model',
    'input_tokens', 'output_tokens', 'cost_usd', 'duration_ms', 'succeeded', 'error',
])]
class AiRun extends Model
{
    use BelongsToCourse;

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AiRunPurpose::class,
            'cost_usd' => 'decimal:6',
            'succeeded' => 'boolean',
        ];
    }
}
