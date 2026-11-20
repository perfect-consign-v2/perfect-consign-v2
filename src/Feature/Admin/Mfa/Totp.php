<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

/**
 * RFC 6238 TOTP: HMAC-SHA1, 6 digits, 30-second step.
 * Callers pass the clock so a test can pin a step.
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    public static function generateSecret(): string
    {
        return random_bytes(20);
    }

    public static function stepForTime(int $unixTimestamp): int
    {
        if ($unixTimestamp < 0) {
            return 0;
        }

        return intdiv($unixTimestamp, self::PERIOD_SECONDS);
    }

    public static function codeForStep(string $rawSecret, int $step): string
    {
        $counter = pack('J', $step);
        $hmac = hash_hmac('sha1', $counter, $rawSecret, true);
        $offset = ord($hmac[19]) & 0x0f;
        $binary = ((ord($hmac[$offset]) & 0x7f) << 24)
            | ((ord($hmac[$offset + 1]) & 0xff) << 16)
            | ((ord($hmac[$offset + 2]) & 0xff) << 8)
            | (ord($hmac[$offset + 3]) & 0xff);
        $code = $binary % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Matching step, or null. A step at or below $lastUsedStep is a replay.
     */
    public static function verify(
        string $rawSecret,
        string $submittedCode,
        int $nowUnixTimestamp,
        int $lastUsedStep = 0,
    ): ?int {
        if ($rawSecret === '' || strlen($submittedCode) !== self::DIGITS || ctype_digit($submittedCode) !== true) {
            return null;
        }
        $center = self::stepForTime($nowUnixTimestamp);
        for ($offset = -1; $offset <= 1; $offset++) {
            $step = $center + $offset;
            if ($step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::codeForStep($rawSecret, $step), $submittedCode)) {
                return $step;
            }
        }

        return null;
    }
}
