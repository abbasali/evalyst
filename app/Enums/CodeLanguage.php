<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Languages offered for open_code questions. Each needs a highlighter in
 * resources/js/lib/markdown.ts and an editor mode in resources/js/components/code/CodeEditor.vue.
 */
enum CodeLanguage: string
{
    use HasOptions;

    case Bash = 'bash';
    case Blade = 'blade';
    case C = 'c';
    case CSharp = 'csharp';
    case Cpp = 'cpp';
    case Css = 'css';
    case Go = 'go';
    case Html = 'html';
    case Java = 'java';
    case JavaScript = 'javascript';
    case Json = 'json';
    case Kotlin = 'kotlin';
    case Php = 'php';
    case Python = 'python';
    case Ruby = 'ruby';
    case Rust = 'rust';
    case Sql = 'sql';
    case Swift = 'swift';
    case TypeScript = 'typescript';
    case PlainText = 'plaintext';

    public function label(): string
    {
        return match ($this) {
            self::Bash => 'Bash',
            self::Blade => 'Blade',
            self::C => 'C',
            self::CSharp => 'C#',
            self::Cpp => 'C++',
            self::Css => 'CSS',
            self::Go => 'Go',
            self::Html => 'HTML',
            self::Java => 'Java',
            self::JavaScript => 'JavaScript',
            self::Json => 'JSON',
            self::Kotlin => 'Kotlin',
            self::Php => 'PHP',
            self::Python => 'Python',
            self::Ruby => 'Ruby',
            self::Rust => 'Rust',
            self::Sql => 'SQL',
            self::Swift => 'Swift',
            self::TypeScript => 'TypeScript',
            self::PlainText => 'Plain text',
        };
    }
}
