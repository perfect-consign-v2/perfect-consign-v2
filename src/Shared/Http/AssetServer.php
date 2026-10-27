<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class AssetServer
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public static function send(string $path): void
    {
        $resolved = self::resolvedFile($path);
        if ($resolved === null) {
            self::fail(404, 'Not found');

            return;
        }
        if (!self::stream($resolved)) {
            self::fail(500, 'The page could not be shown.');
        }
    }

    private static function stream(string $resolved): bool
    {
        $size = filesize($resolved);
        if ($size === false) {
            return false;
        }
        self::headers($resolved, $size);

        return readfile($resolved) !== false;
    }

    private static function headers(string $resolved, int $size): void
    {
        if (headers_sent()) {
            return;
        }
        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        header('Content-Type: ' . (self::TYPES[$extension] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) $size);
        header('Cache-Control: public, max-age=86400');
    }

    private static function resolvedFile(string $path): ?string
    {
        $public = self::publicDirectory();
        $root = realpath($public . DIRECTORY_SEPARATOR . 'assets');
        $resolved = realpath($public . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($root === false || $resolved === false || $root === '/') {
            return null;
        }
        if (!str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    private static function publicDirectory(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public';
    }

    private static function fail(int $status, string $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $body;
    }
}
