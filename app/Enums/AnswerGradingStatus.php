<?php

namespace App\Enums;

enum AnswerGradingStatus: string
{
    case Ungraded = 'ungraded';
    case Pending = 'pending';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Final = 'final';
}
