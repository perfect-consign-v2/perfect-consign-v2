<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Ledger\BasisPoints;
use PHPUnit\Framework\TestCase;

final class BasisPointsTest extends TestCase
{
    public function testPercentStopsAtOneHundred(): void
    {
        self::assertSame(1000, BasisPoints::percent('10'));
        self::assertSame(1050, BasisPoints::percent('10.5'));
        self::assertSame(10000, BasisPoints::percent('100'));
        self::assertNull(BasisPoints::percent('100.01'));
        self::assertNull(BasisPoints::percent(''));
    }

    public function testTaxKeepsThreeDecimals(): void
    {
        self::assertSame(8875, BasisPoints::taxRate('8.875'));
        self::assertSame(100000, BasisPoints::taxRate('100'));
        self::assertNull(BasisPoints::taxRate('100.001'));
    }

    public function testHalfUpMatchesTheSaleFunction(): void
    {
        self::assertSame(200, BasisPoints::halfUp(1000, 2000));
        self::assertSame(0, BasisPoints::halfUp(1002, 0));
    }

    public function testTaxMinorRoundsTheHalfMill(): void
    {
        self::assertSame(80, BasisPoints::tax(1000, 8000));
    }
}
