<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Rent\RentCover;
use PHPUnit\Framework\TestCase;

final class RentCoverTest extends TestCase
{
    public function testAFullPeriodKeepsTheChargeDate(): void
    {
        self::assertSame('', RentCover::partial(1, '2026-10-01', '2026-06-01'));
        self::assertSame('', RentCover::partial(1, '2026-10-01', '2026-10-01'));
    }

    public function testAMoveInRunsFromTheStartThroughThePeriodEnd(): void
    {
        self::assertSame('10-15-2026 through 10-31-2026', RentCover::partial(1, '2026-10-15', '2026-10-15'));
        self::assertSame('09-15-2026 through 09-30-2026', RentCover::partial(1, '2026-09-15', '2026-09-15'));
    }

    public function testALaterCycleDayEndsTheDayBeforeTheNextOpen(): void
    {
        self::assertSame('03-20-2026 through 04-14-2026', RentCover::partial(15, '2026-03-20', '2026-03-20'));
    }
}
