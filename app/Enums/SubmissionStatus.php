<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Submitted = 'submitted';
    case Grading = 'grading';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Final = 'final';
}
