<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use InvalidArgumentException;
use PerfectConsign\Feature\Pos\TicketAdjustment;
use PHPUnit\Framework\TestCase;

final class TicketAdjustmentTest extends TestCase
{
    public function testABlankOrZeroDiscountClearsTheLine(): void
    {
        self::assertSame(
            ['kind' => null, 'value' => null, 'reason' => null],
            TicketAdjustment::discount('apply', 'percent', '', 'promotion')
        );
        self::assertSame(
            ['kind' => null, 'value' => null, 'reason' => null],
            TicketAdjustment::discount('apply', 'amount', '0', 'damaged')
        );
        self::assertSame(
            ['kind' => null, 'value' => null, 'reason' => null],
            TicketAdjustment::discount('apply', 'amount', '0.0', 'damaged')
        );
        self::assertSame(
            ['kind' => null, 'value' => null, 'reason' => null],
            TicketAdjustment::discount('apply', 'amount', '0.00', 'damaged')
        );
        self::assertSame(
            ['kind' => null, 'value' => null, 'reason' => null],
            TicketAdjustment::discount('remove', 'percent', '10', 'promotion')
        );
    }

    public function testAPercentAndAnAmountStayWhenTheyAreReal(): void
    {
        self::assertSame(
            ['kind' => 'percent', 'value' => 1050, 'reason' => 'loyalty'],
            TicketAdjustment::discount('apply', 'percent', '10.5', 'loyalty')
        );
        self::assertSame(
            ['kind' => 'amount', 'value' => 250, 'reason' => 'other'],
            TicketAdjustment::discount('apply', 'amount', '2.50', 'other')
        );
    }

    public function testADiscountWithoutAKindOrAReasonIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TicketAdjustment::discount('apply', 'half', '10', 'promotion');
    }

    public function testADiscountPercentAboveOneHundredIsRefused(): void
    {
        try {
            TicketAdjustment::discount('apply', 'percent', '101', 'promotion');
            self::fail('a percent above 100 was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('enter a percent from 0 to 100', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        TicketAdjustment::discount('apply', 'percent', '10', 'because');
    }

    public function testAWaiverRestoresTaxOrKeepsAKnownReason(): void
    {
        self::assertNull(TicketAdjustment::waiver('restore', 'charity'));
        self::assertSame('resale', TicketAdjustment::waiver('waive', 'resale'));
        $this->expectException(InvalidArgumentException::class);
        TicketAdjustment::waiver('waive', 'friend');
    }
}
