<?php

namespace App\Models;

use App\Concerns\BelongsToCourse;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 */
#[Fillable(['team_id', 'name'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToCourse, HasFactory;

    /**
     * @return BelongsToMany<Question, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class);
    }
}
