<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Ledger\CashRound;
use PHPUnit\Framework\TestCase;

final class CashRoundTest extends TestCase
{
    public function testANickelStaysPut(): void
    {
        self::assertSame(0, CashRound::adjustment(1000, 5));
        self::assertSame(1000, CashRound::rounded(1000, 5));
    }

    public function testOneAndTwoCentsRoundDown(): void
    {
        self::assertSame(-1, CashRound::adjustment(1001, 5));
        self::assertSame(1000, CashRound::rounded(1001, 5));
        self::assertSame(-2, CashRound::adjustment(1002, 5));
        self::assertSame(1000, CashRound::rounded(1002, 5));
    }

    public function testThreeAndFourCentsRoundUp(): void
    {
        self::assertSame(2, CashRound::adjustment(1003, 5));
        self::assertSame(1005, CashRound::rounded(1003, 5));
        self::assertSame(1, CashRound::adjustment(1004, 5));
        self::assertSame(1005, CashRound::rounded(1004, 5));
    }

    public function testADimeRoundsHalfDown(): void
    {
        self::assertSame(-5, CashRound::adjustment(1005, 10));
        self::assertSame(1000, CashRound::rounded(1005, 10));
        self::assertSame(4, CashRound::adjustment(1006, 10));
        self::assertSame(1010, CashRound::rounded(1006, 10));
    }

    public function testAQuarterRounds(): void
    {
        self::assertSame(-12, CashRound::adjustment(1012, 25));
        self::assertSame(1000, CashRound::rounded(1012, 25));
        self::assertSame(12, CashRound::adjustment(1013, 25));
        self::assertSame(1025, CashRound::rounded(1013, 25));
    }

    public function testACentStepNeverMoves(): void
    {
        self::assertSame(0, CashRound::adjustment(1001, 1));
        self::assertSame(1001, CashRound::rounded(1001, 1));
    }
}
