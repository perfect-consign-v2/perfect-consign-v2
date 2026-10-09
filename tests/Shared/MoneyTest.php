<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testDollarsAndCentsBecomeMinorUnits(): void
    {
        self::assertSame(1050, Money::parseMinor('10.50'));
        self::assertSame(1000, Money::parseMinor('10'));
        self::assertSame(1, Money::parseMinor('0.01'));
        self::assertSame(99999999900, Money::parseMinor('999999999'));
    }

    public function testAZeroAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseMinor('0');
    }

    public function testAnAmountThatDoesNotFitInAnIntegerIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the amount is too large');
        Money::parseMinor('1000000000.00');
    }

    public function testANonAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseMinor('10.555');
    }

    public function testMoneyPrintsZeroAndANegativeCent(): void
    {
        self::assertSame('$0.00', View::money(0));
        self::assertSame('$0.01', View::money(1));
        self::assertSame('-$0.01', View::money(-1));
        self::assertSame('$10.50', View::money(1050));
    }

    public function testARowIntegerAcceptsDigitsAndRefusesText(): void
    {
        self::assertSame(12, RowValue::integer(12));
        self::assertSame(12, RowValue::integer('12'));
        self::assertSame(-3, RowValue::integer('-3'));
        $this->expectException(InvalidArgumentException::class);
        RowValue::integer('12abc');
    }
}
