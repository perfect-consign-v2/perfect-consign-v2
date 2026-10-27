<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestCoverage;
use PHPUnit\Framework\TestCase;

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
}
