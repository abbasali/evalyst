<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use RuntimeException;
use Stringable;

/**
 * Base for Evalyst agents: structured output, model from config (D-005),
 * instructions loaded from resources/prompts/{promptFile}.md with {{placeholders}}.
 */
abstract class StructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * File name (without .md) in resources/prompts.
     */
    abstract protected function promptFile(): string;

    /**
     * Values for the {{placeholders}} in the prompt file.
     *
     * @return array<string, string|int|float>
     */
    protected function promptVariables(): array
    {
        return [];
    }

    public function provider(): string
    {
        return (string) config('evalyst.ai.provider');
    }

    public function model(): string
    {
        return (string) config('evalyst.ai.model');
    }

    public function instructions(): Stringable|string
    {
        $path = resource_path("prompts/{$this->promptFile()}.md");
        $template = file_get_contents($path);

        if ($template === false) {
            throw new RuntimeException("Prompt file [{$path}] is missing.");
        }

        $variables = $this->promptVariables();

        return (string) preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $match) => array_key_exists($match[1], $variables) ? (string) $variables[$match[1]] : $match[0],
            $template,
        );
    }
}
