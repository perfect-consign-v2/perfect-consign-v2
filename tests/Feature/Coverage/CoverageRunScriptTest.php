<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PHPUnit\Framework\TestCase;

final class CoverageRunScriptTest extends TestCase
{
    public function testCoverageRunSplitsDefaultSuitesIntoTwoPhpunitProcesses(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'coverage-run.php';
        self::assertFileExists($path);
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('clearCoverageDumps', $script);
        self::assertStringContainsString("runPhpunit(", $script);
        self::assertStringContainsString("'unit,integration'", $script);
        self::assertStringContainsString("'http'", $script);
        self::assertStringContainsString('argvHasTestsuite', $script);
        self::assertSame(2, substr_count($script, "array_merge(['--testsuite'"));
    }

    public function testBootstrapKeepsPriorCoverageDumpsAndWritesUniqueProcessFiles(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';
        self::assertFileExists($path);
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringNotContainsString('coverage dump could not be cleared', $script);
        self::assertStringNotContainsString("glob(\$coverage . DIRECTORY_SEPARATOR . '*.cov')", $script);
        self::assertStringContainsString("process-' . bin2hex(random_bytes(8)) . '.php'", $script);
        self::assertStringNotContainsString("process.php'", $script);
    }
}
