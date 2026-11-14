<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class CoverageKeep
{
    /**
     * @return array<string, array<int, int>>
     */
    public static function lines(mixed $coverage, string $applicationSrc): array
    {
        if (!is_array($coverage)) {
            return [];
        }
        $src = strtolower(str_replace('\\', '/', $applicationSrc . '/'));
        $kept = [];
        foreach ($coverage as $file => $hits) {
            $real = self::file($file, $src);
            if ($real === null || !is_array($hits)) {
                continue;
            }
            $normalized = [];
            foreach ($hits as $line => $count) {
                if (!is_int($line) || !is_int($count)) {
                    continue;
                }
                $normalized[$line] = $count;
            }
            if ($normalized !== []) {
                $kept[$real] = $normalized;
            }
        }

        return $kept;
    }

    public static function file(mixed $file, string $src): ?string
    {
        if (!is_string($file)) {
            return null;
        }
        $real = realpath($file);
        if ($real === false) {
            return null;
        }
        $key = strtolower(str_replace('\\', '/', $real));
        if (!str_starts_with($key, $src)) {
            return null;
        }

        return $real;
    }
}
