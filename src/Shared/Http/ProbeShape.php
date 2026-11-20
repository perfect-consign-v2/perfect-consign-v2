<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class ProbeShape
{
    public static function matches(Request $request): bool
    {
        $path = self::targetPath($request->trace->target);
        if (str_contains($path, '..') || str_contains($path, '//') || self::foreignDot($path)) {
            return true;
        }

        return self::foreignDot($request->path);
    }

    private static function foreignDot(string $path): bool
    {
        if (!str_contains($path, '.')) {
            return false;
        }

        return !self::storeFile($path);
    }

    private static function storeFile(string $path): bool
    {
        if (substr_count($path, '.') !== 1) {
            return false;
        }
        if (!str_ends_with($path, '.csv') && !str_ends_with($path, '.json')) {
            return false;
        }

        return preg_match('#\A/[a-z0-9][a-z0-9./-]*\z#', $path) === 1;
    }

    private static function targetPath(string $target): string
    {
        $cut = strpos($target, '?');
        if ($cut === false) {
            return $target;
        }

        return substr($target, 0, $cut);
    }
}
