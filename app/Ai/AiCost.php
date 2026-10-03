<?php

namespace App\Ai;

use Illuminate\Support\Facades\Log;

class AiCost
{
    /**
     * USD cost of a call, from config('evalyst.ai.pricing') (USD per 1M tokens).
     *
     * Providers report dated snapshots (e.g. "gpt-5.4-mini-2026-08-01"), so the
     * longest pricing key that prefixes the model name is used.
     */
    public static function for(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = self::pricingFor($model);

        if ($pricing === null) {
            Log::warning("No pricing configured for AI model [{$model}]; cost recorded as 0.");

            return 0.0;
        }

        return round(
            $inputTokens / 1_000_000 * (float) $pricing['input'] + $outputTokens / 1_000_000 * (float) $pricing['output'],
            6,
        );
    }

    /**
     * @return array{input: float|int, output: float|int}|null
     */
    private static function pricingFor(string $model): ?array
    {
        /** @var array<string, array{input: float|int, output: float|int}> $table */
        $table = (array) config('evalyst.ai.pricing', []);

        if (isset($table[$model])) {
            return $table[$model];
        }

        $match = collect(array_keys($table))
            ->filter(fn (string $key) => str_starts_with($model, $key))
            ->sortByDesc(fn (string $key) => strlen($key))
            ->first();

        return $match !== null ? $table[$match] : null;
    }
}
