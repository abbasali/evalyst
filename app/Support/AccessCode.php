<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Join codes students type in. No 0/O, 1/I/L to avoid misreads.
 * Roster codes are 8 characters and shared codes 6, so `/join` can tell them apart.
 */
class AccessCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const ROSTER_LENGTH = 8;

    public const SHARED_LENGTH = 6;

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
