<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use DateTimeImmutable;
use PerfectConsign\Feature\Backup\BackupHeadline;
use PHPUnit\Framework\TestCase;

final class BackupHeadlineTest extends TestCase
{
    public function testAMissingDownloadIsNamed(): void
    {
        $now = new DateTimeImmutable('2026-09-26 12:00:00+00');

        self::assertSame('No backup downloaded yet.', BackupHeadline::text(null, $now));
    }

    public function testAgeIsCalendarDaysInUtc(): void
    {
        $completed = new DateTimeImmutable('2026-09-12 18:41:00+00');
        $now = new DateTimeImmutable('2026-09-26 12:00:00+00');

        self::assertSame(
            'Sep 12, 2026, 6:41 PM UTC · 14 days ago',
            BackupHeadline::text($completed, $now)
        );
    }

    public function testTheSameUtcDayIsToday(): void
    {
        $completed = new DateTimeImmutable('2026-09-12 18:41:00+00');
        $now = new DateTimeImmutable('2026-09-12 23:10:00+00');

        self::assertSame(
            'Sep 12, 2026, 6:41 PM UTC · today',
            BackupHeadline::text($completed, $now)
        );
    }

    public function testAFutureCompletionIsStillToday(): void
    {
        $completed = new DateTimeImmutable('2026-09-27 01:00:00+00');
        $now = new DateTimeImmutable('2026-09-26 12:00:00+00');

        self::assertSame(
            'Sep 27, 2026, 1:00 AM UTC · today',
            BackupHeadline::text($completed, $now)
        );
    }

    public function testOneDayIsSingular(): void
    {
        $completed = new DateTimeImmutable('2026-09-12 18:41:00+00');
        $now = new DateTimeImmutable('2026-09-13 00:30:00+00');

        self::assertSame(
            'Sep 12, 2026, 6:41 PM UTC · 1 day ago',
            BackupHeadline::text($completed, $now)
        );
    }
}
