<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Difficulty: string
{
    use HasOptions;

    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
