<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class RequestCoverage
{
    private static bool $written = false;

    public static function enable(mixed $directory, mixed $appEnv): bool
    {
        if (!self::allowed($directory, $appEnv) || !self::xdebugReady()) {
            return false;
        }
        self::$written = false;
        if (function_exists('xdebug_stop_code_coverage')) {
            xdebug_stop_code_coverage(true);
        }
        xdebug_set_filter(
            XDEBUG_FILTER_CODE_COVERAGE,
            XDEBUG_PATH_INCLUDE,
            [self::applicationSrc()]
        );
        // Executed lines only — UNUSED|DEAD_CODE makes long HTTP crawls under
        // coverage take tens of minutes per suite on Windows.
        xdebug_start_code_coverage();

        return true;
    }

    public static function flush(bool $enabled, mixed $directory): void
    {
        if (self::$written || !$enabled || !is_string($directory) || $directory === '') {
            return;
        }
        self::$written = true;
        self::dump($directory);
    }

    private static function allowed(mixed $directory, mixed $appEnv): bool
    {
        return is_string($directory)
            && $directory !== ''
            && is_string($appEnv)
            && in_array($appEnv, ['test', 'coverage'], true);
    }

    private static function xdebugReady(): bool
    {
        if (
            !function_exists('xdebug_start_code_coverage')
            || !defined('XDEBUG_FILTER_CODE_COVERAGE')
            || !defined('XDEBUG_PATH_INCLUDE')
        ) {
            return false;
        }
        $mode = getenv('XDEBUG_MODE');
        if (!is_string($mode) || $mode === '') {
            return true;
        }
        foreach (explode(',', $mode) as $part) {
            if (trim($part) === 'coverage') {
                return true;
            }
        }

        return false;
    }

    private static function dump(string $directory): void
    {
        if (!function_exists('xdebug_get_code_coverage')) {
            return;
        }
        $kept = self::kept(xdebug_get_code_coverage());
        if (function_exists('xdebug_stop_code_coverage')) {
            xdebug_stop_code_coverage(false);
        }
        if ($kept === []) {
            return;
        }
        self::write($directory, $kept);
    }

    /**
     * @return array<string, array<int, int>>
     */
    private static function kept(mixed $coverage): array
    {
        if (!is_array($coverage)) {
            return [];
        }
        $src = strtolower(str_replace('\\', '/', self::applicationSrc() . '/'));
        $kept = [];
        foreach ($coverage as $file => $lines) {
            $real = self::srcFile($file, $src);
            if ($real === null || !is_array($lines)) {
                continue;
            }
            $normalized = [];
            foreach ($lines as $line => $hits) {
                if ((!is_int($line) && !is_string($line)) || !is_int($hits)) {
                    continue;
                }
                $normalized[(int) $line] = $hits;
            }
            if ($normalized !== []) {
                $kept[$real] = $normalized;
            }
        }

        return $kept;
    }

    private static function srcFile(mixed $file, string $src): ?string
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

    private static function applicationSrc(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
    }

    /**
     * @param array<string, array<int, int>> $kept
     */
    private static function write(string $directory, array $kept): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        // serialize is far cheaper than var_export for a large request (demo seed).
        $name = bin2hex(random_bytes(8)) . '.cov';
        $tmp = $directory . DIRECTORY_SEPARATOR . $name . '.tmp';
        $final = $directory . DIRECTORY_SEPARATOR . $name;
        $payload = serialize($kept);
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        if (!rename($tmp, $final) && is_file($tmp)) {
            unlink($tmp);
        }
    }
}
