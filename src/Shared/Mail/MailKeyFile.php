<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use RuntimeException;

final class MailKeyFile
{
    public static function present(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('the environment file is missing');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('the environment file could not be read');
        }
        foreach ($lines as $line) {
            if (self::value($line) !== null) {
                return;
            }
        }
        $key = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL);
        $replaced = false;
        foreach ($lines as $index => $line) {
            if (self::assignment($line)) {
                $lines[$index] = 'STALL_MAIL_KEY=' . $key;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $lines[] = 'STALL_MAIL_KEY=' . $key;
        }
        $written = file_put_contents($path, implode("\n", $lines) . "\n");
        if ($written === false) {
            throw new RuntimeException('the environment file could not be updated');
        }
    }

    private static function value(string $line): ?string
    {
        if (!self::assignment($line)) {
            return null;
        }
        $value = substr($line, strlen('STALL_MAIL_KEY='));
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private static function assignment(string $line): bool
    {
        return str_starts_with($line, 'STALL_MAIL_KEY=');
    }
}
