<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Join codes students type in or read off a projector. Both sides of every look-alike pair
 * are left out (0/O/Q, 1/I/L, 2/Z, 5/S, 6/G, 8/B, U/V), so no character can be misread.
 * Roster and shared codes are both 6 characters and unique across both tables (D-030).
 */
class AccessCode
{
    public const ALPHABET = 'ACDEFHJKMNPRTWXY3479';

    /**
     * Characters that are never used because they look like another character.
     */
    public const AMBIGUOUS = '0OQ1IL2Z5S6G8BUV';

    public const LENGTH = 6;

    public const ROSTER_LENGTH = self::LENGTH;

    public const SHARED_LENGTH = self::LENGTH;

    /**
     * A code not yet used by any participant or assessment.
     */
    public static function generate(int $length): string
    {
        for ($try = 0; $try < 20; $try++) {
            $code = static::random($length);

            if (! self::exists($code)) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique access code.');
    }

    public static function random(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private static function exists(string $code): bool
    {
        return DB::table('participants')->where('access_code', $code)->exists()
            || DB::table('assessments')->where('shared_code', $code)->exists();
    }
}
