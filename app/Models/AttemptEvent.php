<?php

namespace App\Models;

use App\Enums\AttemptEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $attempt_id
 * @property AttemptEventType $type
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
#[Fillable(['attempt_id', 'type', 'occurred_at', 'meta'])]
class AttemptEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Attempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttemptEventType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
