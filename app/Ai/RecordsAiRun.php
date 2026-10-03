<?php

namespace App\Ai;

use App\Enums\AiRunPurpose;
use App\Models\AiRun;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class RecordsAiRun
{
    /**
     * Run an AI call and log it to ai_runs (tokens, cost, duration), whether it succeeds or not.
     *
     * @template TResponse of AgentResponse
     *
     * @param  Model  $subject  A course-owned model (has team_id).
     * @param  Closure(): TResponse  $call
     * @return TResponse
     */
    public function run(AiRunPurpose $purpose, Model $subject, Closure $call): AgentResponse
    {
        $started = hrtime(true);

        try {
            $response = $call();
        } catch (Throwable $exception) {
            $this->record($purpose, $subject, $started, null, $exception);

            throw $exception;
        }

        $this->record($purpose, $subject, $started, $response);

        return $response;
    }

    private function record(AiRunPurpose $purpose, Model $subject, int|float $started, ?AgentResponse $response, ?Throwable $exception = null): void
    {
        $model = ($response?->meta->model ?: null) ?? (string) config('evalyst.ai.model');
        $input = $response?->usage->inputTokens ?? 0;
        $output = $response?->usage->outputTokens ?? 0;

        AiRun::create([
            'team_id' => $subject->getAttribute('team_id'),
            'purpose' => $purpose,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'provider' => ($response?->meta->provider ?: null) ?? (string) config('evalyst.ai.provider'),
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => AiCost::for($model, $input, $output),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'succeeded' => $exception === null,
            'error' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : null,
        ]);
    }
}
