<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\MirrorException;
use PerfectConsign\Feature\Pc1Mirror\Pc1Money;
use PHPUnit\Framework\TestCase;

final class Pc1MoneyTest extends TestCase
{
    public function testDollarsToCents(): void
    {
        self::assertSame(1250, Pc1Money::dollarsToCents('12.50'));
        self::assertSame(-500, Pc1Money::dollarsToCents('-5.00'));
        self::assertSame(100, Pc1Money::dollarsToCents('1'));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(MirrorException::class);
        Pc1Money::dollarsToCents('nope');
    }
}
