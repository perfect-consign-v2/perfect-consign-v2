<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

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
