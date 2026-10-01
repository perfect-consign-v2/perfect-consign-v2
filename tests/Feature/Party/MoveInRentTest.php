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
}
