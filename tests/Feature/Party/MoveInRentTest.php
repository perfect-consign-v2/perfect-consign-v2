<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\MoveInRent;
use PHPUnit\Framework\TestCase;

final class MoveInRentTest extends TestCase
{
    public function testAMoveInOnTheFirstChargesTheFullMonth(): void
    {
        self::assertSame(10000, MoveInRent::due(10000, '2026-09-01'));
    }

    public function testALaterMoveInChargesTheRemainingDays(): void
    {
        self::assertSame(5333, MoveInRent::due(10000, '2026-09-15'));
    }

    public function testADateThatIsNotADayChargesTheFullMonth(): void
    {
        self::assertSame(10000, MoveInRent::due(10000, 'September'));
    }

    public function testAChargeUsesTheRateThenTheMoveInDays(): void
    {
        self::assertSame(8000, MoveInRent::charge(8000, '2026-06-01', '2026-06'));
        self::assertSame(4800, MoveInRent::charge(9000, '2026-06-15', '2026-06'));
        self::assertSame(3400, MoveInRent::charge(6200, '2026-03-15', '2026-03'));
        self::assertSame(6200, MoveInRent::charge(6200, '2026-03-15', '2026-04'));
    }
}
