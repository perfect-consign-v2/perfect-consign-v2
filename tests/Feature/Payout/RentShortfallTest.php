<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Feature\Payout\RentShortfall;
use PHPUnit\Framework\TestCase;

final class RentShortfallTest extends TestCase
{
    public function testAPartialMonthNamesTheDaysAndThePayment(): void
    {
        self::assertSame(
            'Still unpaid from September: $96.66. Eleven days at C-1 was $146.66, and $50.00 was paid.',
            RentShortfall::sentence('September', 'C-1', 11, 14666, 5000, 9666)
        );
    }

    public function testAFullMonthWithNoPaymentNamesTheMonth(): void
    {
        self::assertSame(
            'Still unpaid from August: $400.00. August rent at B-2 was $400.00.',
            RentShortfall::sentence('August', 'B-2', 0, 40000, 0, 40000)
        );
    }
}
