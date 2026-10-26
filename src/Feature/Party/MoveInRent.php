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

    /**
     * The figure stall_assert_rent_charge() will accept: the rate in force for the period, prorated when the stay starts mid-month.
     */
    public static function charge(int $monthly, string $startsOn, string $month): int
    {
        if (substr($startsOn, 0, 7) !== $month) {
            return $monthly;
        }

        return self::due($monthly, $startsOn);
    }
}
