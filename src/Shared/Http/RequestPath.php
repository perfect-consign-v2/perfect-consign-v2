<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class RequestPath
{
    /**
     * @param array<string, mixed> $server
     */
    public static function fromServer(array $server): string
    {
        $uri = self::rawUri($server);
        if (str_starts_with($uri, '//')) {
            return '/';
        }

        return self::normalize(self::fromUri($uri));
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function rawUri(array $server): string
    {
        $uri = $server['REQUEST_URI'] ?? '/';
        if (!is_string($uri) || $uri === '') {
            return '/';
        }
        $cut = strpos($uri, '?');
        if ($cut === false) {
            return $uri;
        }

        return substr($uri, 0, $cut);
    }

    private static function fromUri(string $uri): string
    {
        if (preg_match('#\Ahttps?://#i', $uri) !== 1) {
            return $uri === '' ? '/' : $uri;
        }
        $parsed = parse_url($uri, PHP_URL_PATH);

        return is_string($parsed) && $parsed !== '' ? $parsed : '/';
    }

    private static function normalize(string $path): string
    {
        if (str_contains($path, '..') || str_contains($path, '//')) {
            return '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        if ($path === '' || !str_starts_with($path, '/')) {
            return '/';
        }

        return $path;
    }
}
