<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Scalar\RowValue;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testDollarsAndCentsBecomeMinorUnits(): void
    {
        self::assertSame(1050, Money::parseMinor('10.50'));
        self::assertSame(1000, Money::parseMinor('10'));
        self::assertSame(1, Money::parseMinor('0.01'));
    }

    public function testAZeroAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseMinor('0');
    }

    public function testANonAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseMinor('10.555');
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
