<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum QuestionSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Ai => 'AI generated',
        };
    }
}
