<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

/**
 * Names that browsers, phones, and password managers ask for on their own.
 * The site has none of these files. Each one is an empty 404 that never
 * counts toward a probe ban, so adding the site to a home screen or opening
 * developer tools cannot lock a store out.
 */
final class QuietMiss
{
    /** @var list<string> */
    private const NAMES = [
        '/site.webmanifest',
        '/manifest.json',
        '/manifest.webmanifest',
        '/browserconfig.xml',
    ];

    public static function matches(string $path): bool
    {
        if ($path === '/.well-known' || str_starts_with($path, '/.well-known/')) {
            return true;
        }
        if (in_array($path, self::NAMES, true)) {
            return true;
        }
        if (preg_match('#\A/apple-touch-icon[a-z0-9-]*\.png\z#', $path) === 1) {
            return true;
        }

        return preg_match('#\A/favicon[a-z0-9-]*\.(?:ico|png|svg)\z#', $path) === 1;
    }
}
