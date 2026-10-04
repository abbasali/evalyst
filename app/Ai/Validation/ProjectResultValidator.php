<?php

namespace App\Ai\Validation;

use App\Ai\Agents\ProjectGrader;
use App\Models\AssignmentRule;

class ProjectResultValidator
{
    /**
     * @param  array<string, mixed>  $output  The structured ProjectGrader output.
     * @param  iterable<AssignmentRule>  $rules  The AI rules that were asked for.
     * @return array{rules: array<int, array{score: float, reasoning: string, evidence: list<string>, confidence: float}>, summary: string, flags: list<string>}
     *
     * @throws InvalidAiOutput when a rule is missing, unknown, duplicated or out of range
     */
    public function validate(array $output, iterable $rules): array
    {
        $expected = [];

        foreach ($rules as $rule) {
            $expected[$rule->id] = (float) $rule->marks;
        }

        $results = [];

        foreach ((array) ($output['rules'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['rule_id'], $row['score'], $row['confidence'])) {
                throw new InvalidAiOutput('A rule result is missing fields.');
            }

            $id = (int) $row['rule_id'];

            if (! array_key_exists($id, $expected)) {
                throw new InvalidAiOutput("Unknown rule id {$id}.");
            }

            if (isset($results[$id])) {
                throw new InvalidAiOutput("Rule {$id} was graded twice.");
            }

            $score = (float) $row['score'];
            $confidence = (float) $row['confidence'];

            if ($score < 0 || $score > $expected[$id]) {
                throw new InvalidAiOutput("Rule {$id} score {$score} is outside [0, {$expected[$id]}].");
            }

            if ($confidence < 0 || $confidence > 1) {
                throw new InvalidAiOutput("Rule {$id} confidence {$confidence} is outside [0, 1].");
            }

            $results[$id] = [
                'score' => round($score, 2),
                'reasoning' => trim((string) ($row['reasoning'] ?? '')),
                'evidence' => array_values(array_slice(array_map('strval', (array) ($row['evidence'] ?? [])), 0, 20)),
                'confidence' => $confidence,
            ];
        }

        if ($missing = array_diff(array_keys($expected), array_keys($results))) {
            throw new InvalidAiOutput('Missing results for rule(s) '.implode(', ', $missing).'.');
        }

        $summary = trim((string) ($output['summary'] ?? ''));

        if ($summary === '') {
            throw new InvalidAiOutput('The summary is empty.');
        }

        return [
            'rules' => $results,
            'summary' => $summary,
            'flags' => array_values(array_unique(array_intersect(array_map('strval', (array) ($output['flags'] ?? [])), ProjectGrader::FLAGS))),
        ];
    }
}
