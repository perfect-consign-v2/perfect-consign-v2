<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/**
 * The first month of a stay is the days from move-in through the end of that month.
 */
final class MoveInRent
{
    public static function due(int $monthly, string $startsOn): int
    {
        if (preg_match('/^\d{4}-\d{2}-(\d{2})$/', $startsOn, $match) !== 1) {
            return $monthly;
        }
        $day = (int) $match[1];
        if ($day <= 1) {
            return $monthly;
        }
        $month = substr($startsOn, 0, 8) . '01';
        $daysInMonth = (int) (new \DateTimeImmutable($month))->format('t');
        $daysCharged = $daysInMonth - $day + 1;

        return intdiv($daysCharged * $monthly, $daysInMonth);
    }
}
