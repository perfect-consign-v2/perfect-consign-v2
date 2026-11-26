<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

/**
 * The secret in a signup link. Only its SHA-256 is stored.
 */
final class SignupToken
{
    public const PATTERN = '[A-Za-z0-9_-]{43}';

    /**
     * @return array{token: string, hash: string}
     */
    public static function issue(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function shaped(string $token): bool
    {
        return preg_match('/^' . self::PATTERN . '$/', $token) === 1;
    }
}
