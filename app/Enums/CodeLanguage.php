<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CodeLanguage: string
{
    use HasOptions;

    case Php = 'php';
    case Blade = 'blade';
    case JavaScript = 'javascript';
    case TypeScript = 'typescript';
    case Sql = 'sql';
    case Html = 'html';
    case Css = 'css';
    case Bash = 'bash';
    case Json = 'json';
    case PlainText = 'plaintext';

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::Blade => 'Blade',
            self::JavaScript => 'JavaScript',
            self::TypeScript => 'TypeScript',
            self::Sql => 'SQL',
            self::Html => 'HTML',
            self::Css => 'CSS',
            self::Bash => 'Bash',
            self::Json => 'JSON',
            self::PlainText => 'Plain text',
        };
    }
}
