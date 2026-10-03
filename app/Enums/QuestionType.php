<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum QuestionType: string
{
    use HasOptions;

    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case OpenText = 'open_text';
    case OpenCode = 'open_code';

    public function label(): string
    {
        return match ($this) {
            self::SingleChoice => 'Single choice',
            self::MultipleChoice => 'Multiple choice',
            self::OpenText => 'Open text',
            self::OpenCode => 'Text + code',
        };
    }

    public function isChoice(): bool
    {
        return in_array($this, [self::SingleChoice, self::MultipleChoice], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isChoice();
    }
}
