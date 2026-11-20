<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use RuntimeException;

/**
 * Writes STALL_ADMIN_MFA_KEY into an env file when it is missing.
 * Refuses to invent a key after an authenticator is already sealed.
 */
final class AdminMfaKeyFile
{
    private const NAME = 'STALL_ADMIN_MFA_KEY';

    public static function stored(string $path): ?string
    {
        $lines = self::lines($path);
        foreach ($lines as $line) {
            if (!self::assignment($line)) {
                continue;
            }
            $value = substr($line, strlen(self::NAME . '='));
            if ($value === '') {
                return null;
            }

            return $value;
        }

        return null;
    }

    public static function present(string $path, int $enrolledAdmins): void
    {
        if ($enrolledAdmins < 0) {
            throw new \InvalidArgumentException('the enrolled admin count is invalid');
        }
        $current = self::stored($path);
        if ($current !== null) {
            self::assertKey($current);

            return;
        }
        if ($enrolledAdmins > 0) {
            throw new RuntimeException('STALL_ADMIN_MFA_KEY is missing and an admin authenticator is already sealed');
        }
        $key = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL);
        $lines = self::lines($path);
        $replaced = false;
        foreach ($lines as $index => $line) {
            if (self::assignment($line)) {
                $lines[$index] = self::NAME . '=' . $key;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $lines[] = self::NAME . '=' . $key;
        }
        $written = file_put_contents($path, implode("\n", $lines) . "\n");
        if ($written === false) {
            throw new RuntimeException('the environment file could not be updated');
        }
    }

    private static function assertKey(string $encoded): void
    {
        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException $error) {
            throw new RuntimeException('STALL_ADMIN_MFA_KEY is not a secret-box key', 0, $error);
        }
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('STALL_ADMIN_MFA_KEY is not a secret-box key');
        }
    }

    /**
     * @return list<string>
     */
    private static function lines(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('the environment file is missing');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('the environment file could not be read');
        }

        return $lines;
    }

    private static function assignment(string $line): bool
    {
        return str_starts_with($line, self::NAME . '=');
    }
}
