<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class TlsSignal
{
    /**
     * @param array<string, mixed> $server
     */
    public static function secure(array $server, string $trustedProxiesEnv): bool
    {
        if (self::nativeHttps($server)) {
            return true;
        }
        $remote = $server['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || $remote === '' || !self::trustedPeer($remote, $trustedProxiesEnv)) {
            return false;
        }

        return self::forwardedHttps($server);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function nativeHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function forwardedHttps(array $server): bool
    {
        $forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!is_string($forwarded) || $forwarded === '') {
            return false;
        }
        $comma = strpos($forwarded, ',');
        $token = $comma === false ? $forwarded : substr($forwarded, 0, $comma);

        return strtolower(trim($token)) === 'https';
    }

    private static function trustedPeer(string $remote, string $allowlist): bool
    {
        $allowlist = trim($allowlist);
        if ($allowlist === '') {
            return false;
        }
        foreach (explode(',', $allowlist) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && self::peerMatches($remote, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function peerMatches(string $remote, string $entry): bool
    {
        if (str_contains($entry, '/')) {
            return self::ipv4CidrContains($remote, $entry);
        }

        return strcasecmp($remote, $entry) === 0;
    }

    private static function ipv4CidrContains(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }
        $mask = (int) $parts[1];
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $network = inet_pton($parts[0]);
        $address = inet_pton($ip);
        if ($network === false || $address === false || strlen($network) !== 4 || strlen($address) !== 4) {
            return false;
        }

        return self::maskedEqual($network, $address, $mask);
    }

    private static function maskedEqual(string $network, string $address, int $mask): bool
    {
        $networkLong = unpack('N', $network);
        $addressLong = unpack('N', $address);
        if ($networkLong === false || $addressLong === false) {
            return false;
        }
        $networkBits = $networkLong[1] ?? null;
        $addressBits = $addressLong[1] ?? null;
        if (!is_int($networkBits) || !is_int($addressBits)) {
            return false;
        }
        $bits = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;

        return ($addressBits & $bits) === ($networkBits & $bits);
    }
}
