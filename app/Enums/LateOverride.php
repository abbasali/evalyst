<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LateOverride: string
{
    use HasOptions;

    case Allow = 'allow';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Always allow',
            self::Block => 'Block after the deadline',
        };
    }
}
