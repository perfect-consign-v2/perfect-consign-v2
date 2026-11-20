<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

/**
 * One-time authenticator recovery codes. Plaintext is shown once.
 * The stored value is the SHA-256 of the 20-character normalized code.
 */
final class RecoveryCode
{
    public const BATCH_SIZE = 10;

    public const CODE_LENGTH = 20;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @return list<string>
     */
    public static function batch(): array
    {
        $codes = [];
        for ($index = 0; $index < self::BATCH_SIZE; $index++) {
            $codes[] = self::one();
        }

        return $codes;
    }

    public static function normalize(string $input): string
    {
        $stripped = preg_replace('/[\s\-]+/', '', $input);
        if (!is_string($stripped) || $stripped === '') {
            return '';
        }
        $upper = strtoupper($stripped);
        if (preg_match('/^[A-Z2-7]{' . self::CODE_LENGTH . '}$/', $upper) !== 1) {
            return '';
        }

        return $upper;
    }

    public static function hash(string $normalized): string
    {
        if ($normalized === '') {
            return '';
        }

        return hash('sha256', $normalized);
    }

    private static function one(): string
    {
        $raw = '';
        $length = strlen(self::ALPHABET);
        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $raw .= self::ALPHABET[random_int(0, $length - 1)];
        }

        return substr($raw, 0, 5)
            . '-' . substr($raw, 5, 5)
            . '-' . substr($raw, 10, 5)
            . '-' . substr($raw, 15, 5);
    }
}
