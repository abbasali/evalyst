<?php

namespace App\Ai;

use Illuminate\Support\Facades\Log;

class AiCost
{
    /**
     * USD cost of a call, from config('evalyst.ai.pricing') (USD per 1M tokens).
     */
    public static function for(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = config("evalyst.ai.pricing.{$model}");

        if (! is_array($pricing)) {
            Log::warning("No pricing configured for AI model [{$model}]; cost recorded as 0.");

            return 0.0;
        }

        return round(
            $inputTokens / 1_000_000 * (float) $pricing['input'] + $outputTokens / 1_000_000 * (float) $pricing['output'],
            6,
        );
    }
}
