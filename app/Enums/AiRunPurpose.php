<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AiRunPurpose: string
{
    use HasOptions;

    case QuestionGeneration = 'question_generation';
    case QuestionVerification = 'question_verification';
    case OpenAnswerGrading = 'open_answer_grading';
    case ProjectGrading = 'project_grading';

    public function label(): string
    {
        return match ($this) {
            self::QuestionGeneration => 'Question generation',
            self::QuestionVerification => 'Answer-key verification',
            self::OpenAnswerGrading => 'Answer grading',
            self::ProjectGrading => 'Project grading',
        };
    }
}
