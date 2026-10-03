<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
