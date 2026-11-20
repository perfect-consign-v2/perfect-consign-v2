<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use RuntimeException;

/**
 * Seals the authenticator secret with STALL_ADMIN_MFA_KEY.
 * This is not the mailbox key.
 */
final class AdminMfaSeal
{
    public static function seal(string $plain): string
    {
        if ($plain === '') {
            throw new \InvalidArgumentException('an authenticator secret is required');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $boxed = sodium_crypto_secretbox($plain, $nonce, self::key());

        return 'v1:' . sodium_bin2base64($nonce . $boxed, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public static function open(string $stored): string
    {
        if (!str_starts_with($stored, 'v1:')) {
            throw new RuntimeException('the authenticator secret is not sealed');
        }
        try {
            $raw = sodium_base642bin(substr($stored, 3), SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException $error) {
            throw new RuntimeException('the authenticator secret is not sealed', 0, $error);
        }
        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('the authenticator secret is not sealed');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $boxed = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($boxed, $nonce, self::key());
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('the authenticator secret could not be opened');
        }

        return $plain;
    }

    private static function key(): string
    {
        $encoded = getenv('STALL_ADMIN_MFA_KEY');
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('STALL_ADMIN_MFA_KEY is required');
        }
        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException $error) {
            throw new RuntimeException('STALL_ADMIN_MFA_KEY is not a secret-box key', 0, $error);
        }
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('STALL_ADMIN_MFA_KEY is not a secret-box key');
        }

        return $key;
    }
}
