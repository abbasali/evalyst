<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReleaseMode: string
{
    use HasOptions;

    case Manual = 'manual';
    case Automatic = 'automatic';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
