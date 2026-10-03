<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ChoiceScoringPolicy: string
{
    use HasOptions;

    case AllOrNothing = 'all_or_nothing';
    case Partial = 'partial';
    case PartialWithPenalty = 'partial_with_penalty';

    public function label(): string
    {
        return match ($this) {
            self::AllOrNothing => 'All or nothing',
            self::Partial => 'Partial credit',
            self::PartialWithPenalty => 'Partial credit, wrong picks deduct',
        };
    }
}
