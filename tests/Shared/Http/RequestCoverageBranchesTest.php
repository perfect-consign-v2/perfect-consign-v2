<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestCoverage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class RequestCoverageBranchesTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestCoverage::clearForces();
        $this->resetWritten();
    }

    public function testAMissingCollectorAndAnEmptyBufferWriteNothing(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-empty-' . bin2hex(random_bytes(4));
        RequestCoverage::forceCollection(false);
        self::assertFalse(RequestCoverage::enable($directory, 'coverage'));
        RequestCoverage::flush(true, $directory);
        self::assertFalse(is_dir($directory));

        RequestCoverage::clearForces();
        $this->resetWritten();
        RequestCoverage::forceCollection(true);
        RequestCoverage::forceBuffer([]);
        RequestCoverage::flush(true, $directory);
        self::assertFalse(is_dir($directory));
        self::assertTrue($this->written());
    }

    public function testAllowedAndFlushRefuseABadDirectory(): void
    {
        $allowed = new ReflectionMethod(RequestCoverage::class, 'allowed');
        self::assertFalse($allowed->invoke(null, '', 'coverage'));
        self::assertFalse($allowed->invoke(null, 4, 'coverage'));
        self::assertFalse($allowed->invoke(null, 'dir', 4));
        self::assertFalse($allowed->invoke(null, 'dir', 'production'));
        self::assertTrue($allowed->invoke(null, 'dir', 'test'));
        self::assertTrue($allowed->invoke(null, 'dir', 'coverage'));
        self::assertFalse(RequestCoverage::enable('dir', 'production'));
        self::assertFalse(RequestCoverage::enable(4, 'coverage'));
        RequestCoverage::flush(false, 'dir');
        RequestCoverage::flush(true, '');
        RequestCoverage::flush(true, 4);
    }

    public function testXdebugReadyFollowsTheModeList(): void
    {
        $previous = getenv('XDEBUG_MODE');
        $ready = new ReflectionMethod(RequestCoverage::class, 'xdebugReady');
        try {
            putenv('XDEBUG_MODE');
            self::assertTrue($ready->invoke(null));
            putenv('XDEBUG_MODE=');
            self::assertTrue($ready->invoke(null));
            putenv('XDEBUG_MODE=develop');
            self::assertFalse($ready->invoke(null));
            putenv('XDEBUG_MODE=debug, coverage ');
            self::assertTrue($ready->invoke(null));
            putenv('XDEBUG_MODE=off');
            self::assertFalse($ready->invoke(null));
        } finally {
            $this->restoreEnv('XDEBUG_MODE', $previous);
        }
    }

    public function testKeptAndSrcFileDropRowsOutsideTheApplication(): void
    {
        $src = (new ReflectionMethod(RequestCoverage::class, 'applicationSrc'))->invoke(null);
        self::assertIsString($src);
        $inside = $src . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'RequestCoverage.php';
        $outside = tempnam(sys_get_temp_dir(), 'pc-cov-out-');
        if ($outside === false) {
            self::fail('the outside file could not be created');
        }
        $kept = new ReflectionMethod(RequestCoverage::class, 'kept');
        $srcFile = new ReflectionMethod(RequestCoverage::class, 'srcFile');
        $root = strtolower(str_replace('\\', '/', $src . '/'));
        try {
            self::assertSame([], $kept->invoke(null, 'nope'));
            self::assertNull($srcFile->invoke(null, 12, $root));
            self::assertNull($srcFile->invoke(null, $outside . '-missing', $root));
            self::assertNull($srcFile->invoke(null, $outside, $root));
            self::assertIsString($srcFile->invoke(null, $inside, $root));
            $rows = $kept->invoke(null, [
                4 => [1 => 1],
                $outside => [1 => 1],
                $outside . '-missing' => [1 => 1],
                $inside => ['line' => 1, 12 => 'hit', 18 => 2],
                $inside . '.missing' => [3 => 1],
            ]);
            self::assertIsArray($rows);
            self::assertCount(1, $rows);
            $only = array_values($rows)[0];
            self::assertSame([18 => 2], $only);
        } finally {
            if (is_file($outside)) {
                unlink($outside);
            }
        }
    }

    public function testWriteStoresADumpAndRefusesABrokenDirectory(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-write-' . bin2hex(random_bytes(4));
        $write = new ReflectionMethod(RequestCoverage::class, 'write');
        $inside = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'RequestCoverage.php';
        $real = realpath($inside);
        self::assertNotFalse($real);
        $write->invoke(null, $directory, [$real => [11 => 1]]);
        $dumps = glob($directory . DIRECTORY_SEPARATOR . '*.cov');
        self::assertIsArray($dumps);
        self::assertCount(1, $dumps);
        $payload = file_get_contents($dumps[0]);
        self::assertIsString($payload);
        $decoded = unserialize($payload, ['allowed_classes' => false]);
        self::assertSame([$real => [11 => 1]], $decoded);
        foreach ($dumps as $dump) {
            unlink($dump);
        }
        rmdir($directory);

        $again = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-rename-' . bin2hex(random_bytes(4));
        if (!mkdir($again, 0700) && !is_dir($again)) {
            self::fail('the rename directory could not be created');
        }
        $GLOBALS['pc_rename_fail'] = true;
        $GLOBALS['pc_unlinked'] = false;
        try {
            $write->invoke(null, $again, [$real => [11 => 1]]);
        } finally {
            $GLOBALS['pc_rename_fail'] = false;
        }
        self::assertTrue($GLOBALS['pc_unlinked']);
        if (is_dir($again)) {
            $left = glob($again . DIRECTORY_SEPARATOR . '*') ?: [];
            foreach ($left as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($again);
        }

        $blocked = $this->wrapper();
        set_error_handler(static function (int $severity, string $message): bool {
            return $severity === E_WARNING && str_contains($message, 'Exclusive locks');
        });
        try {
            CoverageFault::$fail = 'mkdir';
            $write->invoke(null, 'covfail://blocked', [$real => [11 => 1]]);
            CoverageFault::$fail = 'write';
            $write->invoke(null, 'covfail://blocked', [$real => [11 => 1]]);
            CoverageFault::$fail = 'rename';
            $write->invoke(null, 'covfail://blocked', [$real => [11 => 1]]);
        } finally {
            restore_error_handler();
        }
        self::assertTrue($blocked);
    }

    public function testDumpKeepsLinesWhenCoverageIsAlreadyRunning(): void
    {
        if (!function_exists('xdebug_get_code_coverage')) {
            self::markTestSkipped('xdebug coverage functions are required');
        }
        $mode = ini_get('xdebug.mode');
        if (!is_string($mode) || !str_contains($mode, 'coverage')) {
            self::markTestSkipped('xdebug coverage mode is required');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-dump-' . bin2hex(random_bytes(4));
        $this->resetWritten();
        RequestCoverage::flush(true, $directory);
        $dumps = glob($directory . DIRECTORY_SEPARATOR . '*.cov') ?: [];
        foreach ($dumps as $dump) {
            unlink($dump);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
        self::assertTrue($this->written());
    }

    public function testEnableStartsWhenCoverageModeIsOn(): void
    {
        $mode = getenv('XDEBUG_MODE');
        if (!function_exists('xdebug_start_code_coverage') || !is_string($mode) || !str_contains($mode, 'coverage')) {
            self::markTestSkipped('xdebug coverage mode is required');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-enable-' . bin2hex(random_bytes(4));
        self::assertTrue(RequestCoverage::enable($directory, 'coverage'));
        RequestCoverage::flush(true, $directory);
        $dumps = glob($directory . DIRECTORY_SEPARATOR . '*.cov') ?: [];
        foreach ($dumps as $dump) {
            unlink($dump);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function wrapper(): bool
    {
        if (in_array('covfail', stream_get_wrappers(), true)) {
            return true;
        }

        return stream_wrapper_register('covfail', CoverageFault::class);
    }

    private function written(): bool
    {
        $property = (new ReflectionClass(RequestCoverage::class))->getProperty('written');

        return (bool) $property->getValue(null);
    }

    private function resetWritten(): void
    {
        $property = (new ReflectionClass(RequestCoverage::class))->getProperty('written');
        $property->setValue(null, false);
    }

    private function restoreEnv(string $name, mixed $previous): void
    {
        if (!is_string($previous)) {
            putenv($name);

            return;
        }
        putenv($name . '=' . $previous);
    }
}

final class CoverageFault
{
    public mixed $context = null;

    public static string $fail = 'mkdir';

    /** @var array<string, string> */
    public static array $files = [];

    public function dir_closedir(): bool
    {
        return true;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        unset($path, $options);

        return true;
    }

    public function dir_readdir(): false
    {
        return false;
    }

    public function dir_rewinddir(): bool
    {
        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        unset($path, $mode, $options);

        return self::$fail !== 'mkdir';
    }

    public function rename(string $from, string $to): bool
    {
        if (self::$fail === 'rename') {
            return false;
        }
        self::$files[$to] = self::$files[$from] ?? '';
        unset(self::$files[$from]);

        return true;
    }

    public function rmdir(string $path, int $options): bool
    {
        unset($path, $options);

        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        unset($operation);

        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($mode, $options, $openedPath);
        if (self::$fail === 'write') {
            return false;
        }
        self::$files[$path] = '';

        return true;
    }

    public function stream_read(int $count): string
    {
        unset($count);

        return '';
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        unset($offset, $whence);

        return true;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return $this->stat(0100666);
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        unset($flags);
        if (self::$fail === 'mkdir') {
            return false;
        }
        if (str_ends_with($path, '.tmp') || str_ends_with($path, '.cov')) {
            if (self::$fail === 'rename' && str_ends_with($path, '.tmp')) {
                return $this->stat(0100666);
            }
            if (!isset(self::$files[$path]) && self::$fail !== 'rename') {
                return false;
            }
            if (self::$fail === 'rename' && str_ends_with($path, '.cov')) {
                return false;
            }

            return $this->stat(0100666);
        }

        return $this->stat(0040777);
    }

    /**
     * @return array<int|string, int>
     */
    private function stat(int $mode): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => $mode,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 0,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => 0,
            'blocks' => 0,
            0 => 0,
            1 => 0,
            2 => $mode,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 0,
            8 => 0,
            9 => 0,
            10 => 0,
            11 => 0,
            12 => 0,
        ];
    }
}

namespace PerfectConsign\Shared\Http;

function xdebug_stop_code_coverage(bool $clean = false): void
{
    $GLOBALS['pc_xdebug_stop'] = $clean;
}

function xdebug_start_code_coverage(int $options = 0): void
{
    $GLOBALS['pc_xdebug_start'] = $options;
}

function rename(string $from, string $to): bool
{
    if (($GLOBALS['pc_rename_fail'] ?? false) === true) {
        return false;
    }

    return \rename($from, $to);
}

function is_file(string $filename): bool
{
    if (($GLOBALS['pc_rename_fail'] ?? false) === true) {
        return true;
    }

    return \is_file($filename);
}

function unlink(string $filename): bool
{
    if (($GLOBALS['pc_rename_fail'] ?? false) === true) {
        $GLOBALS['pc_unlinked'] = true;
        if (\is_file($filename)) {
            return \unlink($filename);
        }

        return true;
    }

    return \unlink($filename);
}
