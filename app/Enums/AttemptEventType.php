<?php

namespace App\Enums;

enum AttemptEventType: string
{
    case FocusLost = 'focus_lost';
    case FocusReturned = 'focus_returned';
    case Pasted = 'pasted';
    case FullscreenExited = 'fullscreen_exited';
    case Resumed = 'resumed';
    case AutoSubmitted = 'auto_submitted';
    case ForceSubmitted = 'force_submitted';
    case ResumeAllowed = 'resume_allowed';

    /**
     * Events the student's browser may report (the rest are written by the server).
     *
     * @return list<self>
     */
    public static function clientReported(): array
    {
        return [self::FocusLost, self::FocusReturned, self::Pasted, self::FullscreenExited];
    }
}
