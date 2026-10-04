<?php

namespace App\Services\GitHub\Checks;

final class Partial
{
    /**
     * Linear partial credit: marks × min(1, fraction), rounded to 2 decimals.
     */
    public static function score(float $marks, float $fraction): float
    {
        return round($marks * max(0.0, min(1.0, $fraction)), 2);
    }
}
