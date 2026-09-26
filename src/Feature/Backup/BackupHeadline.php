<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;

final class BackupHeadline
{
    public static function text(?DateTimeImmutable $completedAt, DateTimeImmutable $now): string
    {
        if ($completedAt === null) {
            return 'No backup downloaded yet.';
        }
        $zone = new DateTimeZone('UTC');
        $completed = $completedAt->setTimezone($zone);
        $today = $now->setTimezone($zone)->setTime(0, 0);
        $completedDay = $completed->setTime(0, 0);
        $days = 0;
        if ($completedDay <= $today) {
            $intervalDays = $completedDay->diff($today)->days;
            $days = $intervalDays === false ? 0 : $intervalDays;
        }
        $age = match ($days) {
            0 => 'today',
            1 => '1 day ago',
            default => $days . ' days ago',
        };

        return $completed->format('M j, Y, g:i A') . ' UTC · ' . $age;
    }
}
