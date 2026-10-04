<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LatePolicy: string
{
    use HasOptions;

    case NotAllowed = 'not_allowed';
    case Allowed = 'allowed';
    case Penalty = 'penalty';

    public function label(): string
    {
        return match ($this) {
            self::NotAllowed => 'No late submissions',
            self::Allowed => 'Late allowed, no penalty',
            self::Penalty => 'Late allowed with a penalty',
        };
    }
}
