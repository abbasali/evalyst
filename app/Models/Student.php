<?php

namespace App\Models;

use App\Concerns\BelongsToCourse;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $roll_number
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Participant> $participants
 */
#[Fillable(['team_id', 'name', 'roll_number', 'email'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToCourse, HasFactory;

    /**
     * Normalise roll numbers so "cs-01 " and "CS-01" are the same student.
     */
    public static function normalizeRollNumber(string $rollNumber): string
    {
        return mb_strtoupper(Str::trim($rollNumber));
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function rollNumber(): Attribute
    {
        return Attribute::make(set: fn (string $value) => static::normalizeRollNumber($value));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::trim((string) $term));

        $query->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhere('roll_number', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")));
    }
}
