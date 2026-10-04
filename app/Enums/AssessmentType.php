<?php

namespace App\Enums;

enum AssessmentType: string
{
    case Quiz = 'quiz';
    case Assignment = 'assignment';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
