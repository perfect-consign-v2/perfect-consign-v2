<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Ledger\CashRound;
use PHPUnit\Framework\TestCase;

final class CashRoundTest extends TestCase
{
    public function testANickelStaysPut(): void
    {
        self::assertSame(0, CashRound::adjustment(1000));
        self::assertSame(1000, CashRound::rounded(1000));
    }

    public function testOneAndTwoCentsRoundDown(): void
    {
        self::assertSame(-1, CashRound::adjustment(1001));
        self::assertSame(1000, CashRound::rounded(1001));
        self::assertSame(-2, CashRound::adjustment(1002));
        self::assertSame(1000, CashRound::rounded(1002));
    }

    public function testThreeAndFourCentsRoundUp(): void
    {
        self::assertSame(2, CashRound::adjustment(1003));
        self::assertSame(1005, CashRound::rounded(1003));
        self::assertSame(1, CashRound::adjustment(1004));
        self::assertSame(1005, CashRound::rounded(1004));
    }
}
