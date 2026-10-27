<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class RequestUploads
{
    /**
     * @param array<string, mixed> $files
     * @return array<string, array{tmp_name: string, error: int, size: int, name: string}>
     */
    public static function normalize(array $files): array
    {
        $uploads = [];
        foreach ($files as $key => $file) {
            if (!is_string($key) || !is_array($file)) {
                continue;
            }
            $parsed = self::single($file);
            if ($parsed === null) {
                continue;
            }
            $uploads[$key] = $parsed;
        }

        return $uploads;
    }

    /**
     * @param array<mixed> $file
     * @return array{tmp_name: string, error: int, size: int, name: string}|null
     */
    private static function single(array $file): ?array
    {
        if (self::isMulti($file)) {
            return null;
        }
        $tmp = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? null;
        $size = $file['size'] ?? null;
        $name = $file['name'] ?? null;
        if (!is_string($tmp) || !is_int($error) || !is_int($size) || !is_string($name)) {
            return null;
        }

        return [
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => $size,
            'name' => $name,
        ];
    }

    /**
     * @param array<mixed> $file
     */
    private static function isMulti(array $file): bool
    {
        return is_array($file['tmp_name'] ?? null)
            || is_array($file['error'] ?? null)
            || is_array($file['size'] ?? null)
            || is_array($file['name'] ?? null);
    }
}
