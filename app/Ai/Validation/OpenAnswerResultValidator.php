<?php

namespace App\Ai\Validation;

use App\Ai\Agents\OpenAnswerGrader;
use App\Grading\AiGradeResult;

class OpenAnswerResultValidator
{
    /**
     * @param  array<string, mixed>  $output  The structured OpenAnswerGrader output.
     *
     * @throws InvalidAiOutput
     */
    public function validate(array $output, float $maxScore): AiGradeResult
    {
        foreach (['score', 'feedback', 'confidence'] as $field) {
            if (! isset($output[$field])) {
                throw new InvalidAiOutput("The AI grade is missing `{$field}`.");
            }
        }

        if (! is_numeric($output['score']) || ! is_numeric($output['confidence']) || ! is_string($output['feedback'])) {
            throw new InvalidAiOutput('The AI grade has fields of the wrong type.');
        }

        $score = (float) $output['score'];
        $confidence = (float) $output['confidence'];

        if ($score < 0 || $score > $maxScore || fmod($score * 2, 1.0) !== 0.0) {
            throw new InvalidAiOutput("The AI score {$score} is not a 0.5 step within [0, {$maxScore}].");
        }

        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidAiOutput("The AI confidence {$confidence} is outside [0, 1].");
        }

        if (trim($output['feedback']) === '') {
            throw new InvalidAiOutput('The AI feedback is empty.');
        }

        $breakdown = array_values(collect(is_array($output['breakdown'] ?? null) ? $output['breakdown'] : [])
            ->filter(fn ($row) => is_array($row) && isset($row['criterion']))
            ->map(fn (array $row) => [
                'criterion' => (string) $row['criterion'],
                'awarded' => (float) ($row['awarded'] ?? 0),
                'max' => (float) ($row['max'] ?? 0),
                'note' => (string) ($row['note'] ?? ''),
            ])
            ->all());

        $flags = array_values(array_unique(array_intersect(
            array_map('strval', is_array($output['flags'] ?? null) ? $output['flags'] : []),
            OpenAnswerGrader::FLAGS,
        )));

        return new AiGradeResult($score, trim($output['feedback']), $breakdown, $confidence, $flags);
    }
}
