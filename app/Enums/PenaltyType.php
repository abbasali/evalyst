<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PenaltyType: string
{
    use HasOptions;

    case Fixed = 'fixed';
    case PerHour = 'per_hour';
    case PerDay = 'per_day';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed deduction',
            self::PerHour => 'Per started hour',
            self::PerDay => 'Per started day',
        };
    }
}
