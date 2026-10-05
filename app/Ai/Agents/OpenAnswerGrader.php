<?php

namespace App\Ai\Agents;

use App\Enums\CodeLanguage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;

/**
 * Grades one open_text / open_code answer against the rubric. See docs/03-ai.md §3.
 * Question context goes in the instructions; the untrusted student answer is the user message.
 */
#[Strict]
#[Timeout(150)]
class OpenAnswerGrader extends StructuredAgent
{
    public const FLAGS = ['prompt_injection', 'off_topic', 'possibly_ai_generated', 'blank_or_minimal', 'rubric_ambiguous'];

    public function __construct(
        public string $question,
        public ?string $modelAnswer,
        public ?string $rubric,
        public float $maxMarks,
        public ?string $codeLanguage = null,
    ) {}

    protected function promptFile(): string
    {
        return 'open-answer-grader';
    }

    protected function promptVariables(): array
    {
        return [
            'question' => $this->question,
            'model_answer' => $this->modelAnswer ?: '(none provided)',
            'rubric' => $this->rubric ?: '(none provided: grade against the model answer)',
            'max_marks' => self::formatMarks($this->maxMarks),
            'language_note' => match ($this->codeLanguage) {
                null => '',
                CodeLanguage::PlainText->value => 'This is a code question. The student writes an explanation and code; the language isn\'t specified, so infer it from the question.',
                default => 'This is a code question. The student writes an explanation and code in '.(CodeLanguage::tryFrom($this->codeLanguage)?->label() ?? $this->codeLanguage).'.',
            },
        ];
    }

    /**
     * The user message: the student's answer wrapped in untrusted-data blocks.
     */
    public static function buildPrompt(?string $text, ?string $code, ?string $codeLanguage = null): string
    {
        $prompt = "Grade this student's answer.\n\n<student_answer>\n".self::neutralise(trim((string) $text))."\n</student_answer>";

        if ($codeLanguage !== null) {
            $prompt .= "\n\n<student_code language=\"{$codeLanguage}\">\n".self::neutralise(trim((string) $code))."\n</student_code>";
        }

        return $prompt;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->number()->required(),
            'feedback' => $schema->string()->required(),
            'breakdown' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'criterion' => $schema->string()->required(),
                'awarded' => $schema->number()->required(),
                'max' => $schema->number()->required(),
                'note' => $schema->string()->required(),
            ]))->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'flags' => $schema->array()->items($schema->string()->enum(self::FLAGS))->required(),
        ];
    }

    private static function formatMarks(float $marks): string
    {
        return rtrim(rtrim(number_format($marks, 2, '.', ''), '0'), '.');
    }

    /**
     * Stop a student from closing the block early and smuggling text outside it.
     */
    private static function neutralise(string $content): string
    {
        return (string) preg_replace('#<\s*/?\s*student_(answer|code)\b[^>]*>#i', '[removed tag]', $content);
    }
}
