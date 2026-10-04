<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AccessMode: string
{
    use HasOptions;

    case Roster = 'roster';
    case SharedCode = 'shared_code';

    public function label(): string
    {
        return match ($this) {
            self::Roster => 'Roster codes',
            self::SharedCode => 'Shared code',
        };
    }
}
