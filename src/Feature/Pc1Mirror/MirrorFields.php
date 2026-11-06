<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

final class MirrorFields
{
    public static function text(mixed $value, string $fallback = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    public static function cents(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new MirrorException('expected integer cents');
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
