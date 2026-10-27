<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class SameHostReferer
{
    private const MAX_LENGTH = 2048;

    /**
     * @param array<string, mixed> $server
     */
    public static function path(array $server, bool $https): string
    {
        $referer = $server['HTTP_REFERER'] ?? '';
        $host = $server['HTTP_HOST'] ?? '';
        if (!is_string($referer) || !is_string($host) || $referer === '' || $host === '') {
            return '';
        }
        if (strlen($referer) > self::MAX_LENGTH) {
            return '';
        }
        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return '';
        }
        if (!self::schemeOk($parts, $https) || !self::hostOk($parts, $host)) {
            return '';
        }

        return self::absolutePath($parts);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function schemeOk(array $parts, bool $https): bool
    {
        $scheme = $parts['scheme'] ?? null;
        if (!is_string($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
            return false;
        }

        return !($https && $scheme !== 'https');
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function hostOk(array $parts, string $host): bool
    {
        $refererHost = $parts['host'] ?? null;
        if (!is_string($refererHost)) {
            return false;
        }
        $requestHost = preg_replace('/:\d+$/', '', strtolower($host));

        return is_string($requestHost) && strtolower($refererHost) === $requestHost;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function absolutePath(array $parts): string
    {
        $path = $parts['path'] ?? null;
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }

        return $path;
    }
}
