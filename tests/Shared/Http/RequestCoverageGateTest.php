<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Bootstrap\RolePaths;
use PerfectConsign\Shared\Http\RequestCoverage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class RequestCoverageGateTest extends TestCase
{
    protected function tearDown(): void
    {
        $property = (new ReflectionClass(RequestCoverage::class))->getProperty('written');
        $property->setValue(null, false);
    }

    public function testProductionAppEnvRefusesCoverageStart(): void
    {
        self::assertFalse(RequestCoverage::enable(sys_get_temp_dir() . '/pc-cov-gate', 'production'));
        self::assertFalse(RequestCoverage::enable(sys_get_temp_dir() . '/pc-cov-gate', 'local'));
    }

    public function testEmptyDirectoryRefusesCoverageStart(): void
    {
        self::assertFalse(RequestCoverage::enable('', 'test'));
        self::assertFalse(RequestCoverage::enable(null, 'coverage'));
    }

    public function testApplicationSrcIsTheRepositorySrcTree(): void
    {
        $method = new ReflectionMethod(RequestCoverage::class, 'applicationSrc');
        $path = $method->invoke(null);
        self::assertIsString($path);
        $expected = realpath(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        self::assertNotFalse($expected);
        self::assertSame($expected, realpath($path));
        self::assertFileExists($path . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'RequestCoverage.php');
    }

    public function testAllowedGateRequiresDirectoryAndTestEnv(): void
    {
        $allowed = new ReflectionMethod(RequestCoverage::class, 'allowed');
        self::assertTrue($allowed->invoke(null, sys_get_temp_dir(), 'test'));
        self::assertTrue($allowed->invoke(null, sys_get_temp_dir(), 'coverage'));
        self::assertFalse($allowed->invoke(null, sys_get_temp_dir(), 'production'));
        self::assertFalse($allowed->invoke(null, '', 'test'));
        self::assertFalse($allowed->invoke(null, 0, 'test'));
        self::assertFalse($allowed->invoke(null, sys_get_temp_dir(), 0));
    }

    public function testFlushRefusesWhenDisabledOrDirectoryInvalid(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-flush-gate';
        RequestCoverage::flush(false, $dir);
        RequestCoverage::flush(true, '');
        RequestCoverage::flush(true, null);
        self::assertFalse($this->coverageWritten());
    }

    public function testSecondFlushIsIgnoredAfterFirst(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-flush-once';
        RequestCoverage::flush(true, $dir);
        self::assertTrue($this->coverageWritten());
        RequestCoverage::flush(true, $dir);
        self::assertTrue($this->coverageWritten());
    }

    public function testFlushDoesNotStopSuiteCoverageCollection(): void
    {
        if (!function_exists('xdebug_get_code_coverage') || !function_exists('xdebug_start_code_coverage')) {
            self::markTestSkipped('xdebug coverage is required');
        }
        $mode = getenv('XDEBUG_MODE');
        if (!is_string($mode) || !str_contains($mode, 'coverage')) {
            self::markTestSkipped('XDEBUG_MODE=coverage is required');
        }
        xdebug_start_code_coverage();
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cov-flush-keeps-session';
        RequestCoverage::flush(true, $dir);
        self::assertTrue($this->coverageWritten());
        self::assertTrue(RolePaths::underFloor('/customers/new'));
        $after = xdebug_get_code_coverage();
        self::assertIsArray($after);
        $hit = false;
        foreach ($after as $file => $lines) {
            if (!is_string($file) || !str_contains(str_replace('\\', '/', $file), '/Bootstrap/RolePaths.php')) {
                continue;
            }
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $count) {
                if (is_int($count) && $count > 0) {
                    $hit = true;
                    break 2;
                }
            }
        }
        self::assertTrue($hit, 'flush must not stop xdebug so later lines still record');
    }

    private function coverageWritten(): bool
    {
        $property = (new ReflectionClass(RequestCoverage::class))->getProperty('written');

        return (bool) $property->getValue(null);
    }

    public function testEnableRefusesWithoutXdebugReady(): void
    {
        if (function_exists('xdebug_start_code_coverage') && extension_loaded('xdebug')) {
            self::markTestSkipped('xdebug is loaded; refusal without coverage mode is environment-specific');
        }
        self::assertFalse(RequestCoverage::enable(sys_get_temp_dir() . '/pc-cov-no-xdebug', 'test'));
    }
}
