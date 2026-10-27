<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestCoverage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RequestCoverageGateTest extends TestCase
{
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
}
