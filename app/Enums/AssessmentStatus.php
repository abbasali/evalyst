<?php

namespace App\Enums;

/**
 * Whether a published assessment is upcoming/open/closed is derived from its dates.
 */
enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
