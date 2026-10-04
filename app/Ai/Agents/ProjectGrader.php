<?php

namespace App\Ai\Agents;

use App\Data\GitHubCommit;
use App\Data\RepoSnapshot;
use App\Models\AssignmentRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;

/**
 * Grades every AI rule of one submission in a single call. See docs/03-ai.md §4.
 * The instructor's statement and rules go in the instructions; repo content is the user message.
 */
#[Strict]
#[Timeout(240)]
class ProjectGrader extends StructuredAgent
{
    public const FLAGS = ['prompt_injection', 'repo_mostly_empty', 'unrelated_to_problem'];

    /**
     * @param  iterable<AssignmentRule>  $rules  The AI rules only.
     */
    public function __construct(public string $statement, public iterable $rules) {}

    protected function promptFile(): string
    {
        return 'project-grader';
    }

    protected function promptVariables(): array
    {
        $rules = [];

        foreach ($this->rules as $rule) {
            $rules[] = "- id {$rule->id}: **{$rule->title}** (max ".(float) $rule->marks.")\n  Judge: ".trim((string) $rule->description);
        }

        return [
            'statement' => $this->statement,
            'rules' => implode("\n", $rules),
        ];
    }

    /**
     * The user message: automated results (context), commit log, tree and file contents.
     *
     * @param  list<string>  $automatedResults  One line per automated check.
     */
    public static function buildPrompt(RepoSnapshot $snapshot, array $automatedResults): string
    {
        $commits = array_map(
            fn (GitHubCommit $commit) => substr($commit->sha, 0, 7).' | '.($commit->date?->format('Y-m-d H:i') ?? '?').' | '.self::line($commit->message),
            array_slice($snapshot->commits, 0, 150),
        );

        $skipped = [];

        foreach (array_slice($snapshot->skipped, 0, 200, true) as $path => $reason) {
            $skipped[] = self::line($path)." ({$reason})";
        }

        $files = [];

        foreach ($snapshot->includedFiles as $path => $content) {
            $files[] = '<file path="'.htmlspecialchars(self::line($path), ENT_QUOTES).'">'."\n".self::neutralise($content)."\n</file>";
        }

        // Everything from the repository (commit messages and paths too) sits inside one untrusted block.
        $repository = implode("\n\n", array_filter([
            '### Commit log (newest first, '.count($snapshot->commits)." commits)\n".($commits === [] ? '(none)' : implode("\n", $commits)),
            "### Files included below\n".implode("\n", array_map(self::line(...), array_keys($snapshot->includedFiles))),
            $skipped !== [] ? "### Files not included\n".implode("\n", $skipped) : null,
            "### File contents\n".implode("\n\n", $files),
        ]));

        return implode("\n\n", array_filter([
            "Repository {$snapshot->owner}/{$snapshot->repo} at commit {$snapshot->sha}.",
            $automatedResults !== [] ? "## Automated check results (context only)\n".implode("\n", $automatedResults) : null,
            $snapshot->truncated ? 'Note: the repository is very large; GitHub returned a truncated file tree.' : null,
            "## The student's repository (untrusted)\n<repository>\n{$repository}\n</repository>",
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rules' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'rule_id' => $schema->integer()->required(),
                'score' => $schema->number()->required(),
                'reasoning' => $schema->string()->required(),
                'evidence' => $schema->array()->items($schema->string())->required(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
            ]))->required(),
            'summary' => $schema->string()->required(),
            'flags' => $schema->array()->items($schema->string()->enum(self::FLAGS))->required(),
        ];
    }

    /**
     * Stop repo content from closing a file block early.
     */
    private static function neutralise(string $content): string
    {
        return (string) preg_replace('#<\s*/?\s*(file|repository)\b[^>]*>#i', '[removed tag]', $content);
    }

    /**
     * Commit messages and paths on one line, without block tags.
     */
    private static function line(string $value): string
    {
        return self::neutralise((string) preg_replace('/\s+/', ' ', $value));
    }
}
