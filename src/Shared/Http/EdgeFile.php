<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

/**
 * Files a browser requests by name. Apache serves them as real files.
 * php -S routes every request through index.php, so the same names are sent here.
 */
final class EdgeFile
{
    /** @var array<string, string> */
    private const TYPES = [
        '/robots.txt' => 'text/plain; charset=utf-8',
        '/favicon.ico' => 'image/x-icon',
        '/apple-touch-icon.png' => 'image/png',
        '/apple-touch-icon-precomposed.png' => 'image/png',
    ];

    public static function send(string $path): bool
    {
        $type = self::TYPES[$path] ?? null;
        if ($type === null) {
            return false;
        }
        $full = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($full)) {
            return false;
        }
        $size = filesize($full);
        if ($size === false) {
            return false;
        }
        header('Content-Type: ' . $type);
        header('Content-Length: ' . (string) $size);
        $sent = readfile($full);

        return $sent !== false;
    }
}
