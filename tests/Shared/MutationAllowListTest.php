<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

final class MutationAllowListTest extends TestCase
{
    public function testALinuxCoveragePathMatchesTheAllowList(): void
    {
        putenv('PC_MUTATE_FUNCTIONS=1');
        require_once dirname(__DIR__, 2) . '/bin/mutate.php';
        putenv('PC_MUTATE_FUNCTIONS');

        $linux = relative('/workspace/repo', '/workspace/repo/src/Feature/Rent/RentFigure.php');
        self::assertSame('src\\Feature\\Rent\\RentFigure.php', $linux);
        self::assertNotNull(equivalentReason($linux, 35, '&&', '||'));
        self::assertNotNull(equivalentReason($linux, 35, '>', '>='));

        $windows = relative(
            'C:\\laragon\\www\\perfect-consign',
            'C:\\laragon\\www\\perfect-consign\\src\\Feature\\Rent\\RentFigure.php'
        );
        self::assertSame('src\\Feature\\Rent\\RentFigure.php', $windows);
        self::assertNotNull(equivalentReason($windows, 35, '&&', '||'));
    }

    public function testARunThatMutatesNothingIsRefused(): void
    {
        $this->loadHarness();
        self::assertFalse(harnessWasInvoked());
        self::assertSame(313, mutantMinimum());
        self::assertNotNull(tooFewMutants(0));
        self::assertNotNull(tooFewMutants(312));
        self::assertNull(tooFewMutants(313));

        $root = dirname(__DIR__, 2);
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['PC_MUTATE_FUNCTIONS'] = '1';
        $process = proc_open(
            [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mutate.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env
        );
        self::assertIsResource($process);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertSame(1, $code);
        self::assertIsString($error);
        self::assertStringContainsString('PC_MUTATE_FUNCTIONS', $error);
    }

    private function loadHarness(): void
    {
        putenv('PC_MUTATE_FUNCTIONS=1');
        require_once dirname(__DIR__, 2) . '/bin/mutate.php';
        putenv('PC_MUTATE_FUNCTIONS');
    }
}
