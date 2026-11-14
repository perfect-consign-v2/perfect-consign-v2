<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class RequestCoverage
{
    private static bool $written = false;

    private static bool $collectForced = false;

    private static bool $collect = false;

    private static bool $bufferForced = false;

    private static mixed $buffer = null;

    /**
     * Test seam. Production leaves this unset and asks the extension.
     */
    public static function forceCollection(bool $collect): void
    {
        self::$collectForced = true;
        self::$collect = $collect;
    }

    /**
     * Test seam. Production leaves this unset and reads the live buffer.
     */
    public static function forceBuffer(mixed $buffer): void
    {
        self::$bufferForced = true;
        self::$buffer = $buffer;
    }

    public static function clearForces(): void
    {
        self::$collectForced = false;
        self::$collect = false;
        self::$bufferForced = false;
        self::$buffer = null;
    }

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
        if (!self::collectionAvailable()) {
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
        if (!self::collectionAvailable()) {
            return;
        }
        // Do not stop coverage here. The PHPUnit bootstrap starts one suite-wide
        // session; stopping mid-suite left process dumps nearly empty. The next
        // enable() clears before a new HTTP request.
        $kept = self::kept(self::buffer());
        if ($kept === []) {
            return;
        }
        self::write($directory, $kept);
    }

    private static function collectionAvailable(): bool
    {
        if (self::$collectForced) {
            return self::$collect;
        }

        return function_exists('xdebug_start_code_coverage')
            && defined('XDEBUG_FILTER_CODE_COVERAGE')
            && defined('XDEBUG_PATH_INCLUDE')
            && function_exists('xdebug_get_code_coverage');
    }

    private static function buffer(): mixed
    {
        if (self::$bufferForced) {
            return self::$buffer;
        }

        return xdebug_get_code_coverage();
    }

    /**
     * @return array<string, array<int, int>>
     */
    private static function kept(mixed $coverage): array
    {
        return CoverageKeep::lines($coverage, self::applicationSrc());
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
