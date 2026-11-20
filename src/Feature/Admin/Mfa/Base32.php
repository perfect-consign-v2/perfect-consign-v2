<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

/**
 * RFC 4648 base32, the unpadded alphabet authenticator apps expect.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $bits = '';
        $length = strlen($raw);
        for ($index = 0; $index < $length; $index++) {
            $bits .= str_pad(decbin(ord($raw[$index])), 8, '0', STR_PAD_LEFT);
        }
        $pad = (5 - (strlen($bits) % 5)) % 5;
        if ($pad > 0) {
            $bits .= str_repeat('0', $pad);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $encoded;
    }

    public static function decode(string $encoded): ?string
    {
        $encoded = strtoupper(str_replace([' ', '-'], '', $encoded));
        $encoded = rtrim($encoded, '=');
        if ($encoded === '') {
            return null;
        }
        $bits = '';
        $length = strlen($encoded);
        for ($index = 0; $index < $length; $index++) {
            $place = strpos(self::ALPHABET, $encoded[$index]);
            if ($place === false) {
                return null;
            }
            $bits .= str_pad(decbin($place), 5, '0', STR_PAD_LEFT);
        }
        $raw = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) !== 8) {
                continue;
            }
            $character = self::byte($byte);
            if ($character === null) {
                return null;
            }
            $raw .= $character;
        }
        if ($raw === '') {
            return null;
        }

        return $raw;
    }

    private static function byte(string $bits): ?string
    {
        $value = bindec($bits);
        if (!is_int($value) || $value < 0 || $value > 255) {
            return null;
        }

        return chr($value);
    }

    public static function groups(string $encoded): string
    {
        return implode(' ', str_split($encoded, 4));
    }
}
