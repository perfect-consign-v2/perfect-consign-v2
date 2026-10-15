<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

/**
 * The dates a rent charge covers. A stay that starts after the period opens runs from that day through the period end.
 */
final class RentCover
{
    public static function partial(int $cycleDay, string $postingDate, string $startsOn): string
    {
        $day = $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;
        $cycle = new RentCycle($day);
        $start = $cycle->start($postingDate);
        $end = $cycle->end($start);
        if ($startsOn <= $start || $startsOn > $end) {
            return '';
        }

        return self::day($startsOn) . ' through ' . self::day($end);
    }

    private static function day(string $date): string
    {
        return substr($date, 5, 2) . '-' . substr($date, 8, 2) . '-' . substr($date, 0, 4);
    }
}
