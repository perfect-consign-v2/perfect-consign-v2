<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\Pc1BillingCalendar;
use PHPUnit\Framework\TestCase;

final class Pc1BillingCalendarTest extends TestCase
{
    public function testBillingMonthBoundariesOnCycleDay(): void
    {
        $calendar = new Pc1BillingCalendar();
        $day = new \DateTimeImmutable('2026-03-15');
        $start = $calendar->startOfBillingMonthContaining($day, 1);
        $end = $calendar->endOfBillingMonthContaining($day, 1);
        self::assertSame('2026-03-01', $start->format('Y-m-d'));
        self::assertSame('2026-03-31', $end->format('Y-m-d'));
    }

    public function testBillingMonthRollsBackBeforeCycleDay(): void
    {
        $calendar = new Pc1BillingCalendar();
        $day = new \DateTimeImmutable('2026-03-05');
        $start = $calendar->startOfBillingMonthContaining($day, 10);
        $end = $calendar->endOfBillingMonthContaining($day, 10);
        self::assertSame('2026-02-10', $start->format('Y-m-d'));
        self::assertSame('2026-03-09', $end->format('Y-m-d'));
    }
}
