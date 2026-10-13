<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use InvalidArgumentException;
use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Feature\Mall\MallWrite;
use PHPUnit\Framework\TestCase;

final class MallGuardTest extends TestCase
{
    public function testAMallWriteKeepsThePostedFlags(): void
    {
        $write = MallWrite::posted(10, 8, 120, 90, [
            'color' => true,
            'category' => false,
            'size' => true,
            'condition' => false,
            'department' => true,
            'brand' => false,
        ]);

        self::assertSame(10, $write->boothWidth);
        self::assertSame(8, $write->boothDepth);
        self::assertSame(120, $write->floorWidth);
        self::assertSame(90, $write->floorDepth);
        self::assertSame(
            ['true', 'false', 'true', 'false', 'true', 'false'],
            $write->flags->stored()
        );
    }

    public function testAPostgresFlagIsReadAsOn(): void
    {
        self::assertTrue(MallGuard::flag(true));
        self::assertTrue(MallGuard::flag(1));
        self::assertTrue(MallGuard::flag('1'));
        self::assertTrue(MallGuard::flag('t'));
        self::assertTrue(MallGuard::flag('true'));
        self::assertFalse(MallGuard::flag(false));
        self::assertFalse(MallGuard::flag('false'));
        self::assertFalse(MallGuard::flag(null));
    }

    public function testAMallSettingOutsideItsRangeIsRefused(): void
    {
        MallGuard::requireZone('America/Los_Angeles');
        MallGuard::requireFeet(1, 1, 'booth size is whole feet');
        MallGuard::requireTake(0);
        MallGuard::requireTake(10000);
        MallGuard::requireTax(0);
        MallGuard::requireTax(100000);
        MallGuard::guardDays(null);
        MallGuard::guardDays(1);
        MallGuard::guardDays(365);
        $this->refused(static fn (): mixed => MallGuard::requireZone('Mars/Base'), 'that time zone is not an IANA name');
        $this->refused(static fn (): mixed => MallGuard::requireFeet(0, 1, 'booth size is whole feet'), 'booth size is whole feet');
        $this->refused(static fn (): mixed => MallGuard::requireFeet(1, 0, 'floor size is whole feet'), 'floor size is whole feet');
        $this->refused(static fn (): mixed => MallGuard::requireTake(-1), 'store take is a percent from 0 to 100');
        $this->refused(static fn (): mixed => MallGuard::requireTake(10001), 'store take is a percent from 0 to 100');
        $this->refused(static fn (): mixed => MallGuard::requireTax(-1), 'tax rate is a percent from 0 to 100');
        $this->refused(static fn (): mixed => MallGuard::requireTax(100001), 'tax rate is a percent from 0 to 100');
        $this->refused(static fn (): mixed => MallGuard::guardDays(0), 'days on the floor is a whole number from 1 to 365, or empty');
        $this->refused(static fn (): mixed => MallGuard::guardDays(366), 'days on the floor is a whole number from 1 to 365, or empty');
    }

    private function refused(callable $call, string $message): void
    {
        try {
            $call();
            self::fail($message);
        } catch (InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}
