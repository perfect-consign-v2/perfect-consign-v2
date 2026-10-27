<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use RuntimeException;

final class RequestOrigin
{
    /**
     * @param array<string, mixed> $server
     */
    public static function resolve(array $server, bool $https): string
    {
        $configured = getenv('STALL_PUBLIC_ORIGIN');
        if (is_string($configured) && preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $configured) === 1) {
            return $configured;
        }
        if (!self::allowHost()) {
            throw new RuntimeException('STALL_PUBLIC_ORIGIN is required');
        }
        $host = $server['HTTP_HOST'] ?? '';
        if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) !== 1) {
            throw new RuntimeException('STALL_PUBLIC_ORIGIN is required');
        }

        return ($https ? 'https' : 'http') . '://' . $host;
    }

    private static function allowHost(): bool
    {
        $env = getenv('APP_ENV');
        if (is_string($env) && in_array(strtolower($env), ['production', 'prod'], true)) {
            return false;
        }
        if (getenv('STALL_ALLOW_HOST_ORIGIN') !== 'true') {
            return false;
        }
        if (is_string($env) && in_array($env, ['local', 'development', 'dev'], true)) {
            return true;
        }

        return getenv('APP_DEBUG') === 'true';
    }
}
