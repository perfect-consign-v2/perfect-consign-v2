<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class EnvFile
{
    public static function apply(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return;
        }
        foreach (self::parse($contents) as $key => $value) {
            if (getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . $value);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $vars = [];
        $lines = preg_split("/\r\n|\n|\r/", $contents);
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $line) {
            $pair = self::line($line);
            if ($pair === null) {
                continue;
            }
            $vars[$pair[0]] = $pair[1];
        }

        return $vars;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function line(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }
        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = trim(substr($trimmed, 7));
        }
        $separator = strpos($trimmed, '=');
        if ($separator === false) {
            return null;
        }
        $key = trim(substr($trimmed, 0, $separator));
        if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            return null;
        }
        return [$key, self::value(substr($trimmed, $separator + 1))];
    }

    private static function value(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $first = $raw[0];
        if ($first === '"' || $first === "'") {
            return self::quoted($raw, $first);
        }
        $hash = strpos($raw, ' #');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }

        return trim($raw);
    }

    private static function quoted(string $raw, string $quote): string
    {
        $length = strlen($raw);
        $value = '';
        for ($i = 1; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $next = $raw[$i + 1];
                if ($next === $quote || $next === '\\') {
                    $value .= $next;
                    $i++;
                    continue;
                }
            }
            if ($char === $quote) {
                return $value;
            }
            $value .= $char;
        }

        return $value;
    }
}
