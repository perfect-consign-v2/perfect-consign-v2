<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

/**
 * The charge date and the amount the ledger will accept for one booth in one month.
 */
final class RentFigure
{
    /**
     * @return array{date: string, chargeable: bool, amount: ?int}
     */
    public static function posted(
        ?int $rate,
        ?string $startsOn,
        ?string $endsOn,
        ?int $override,
        string $month,
        string $today,
        int $cycleDay = 1,
        ?string $pinned = null,
    ): array {
        $date = $pinned ?? self::chargeDate($month, $today, $startsOn);
        if ($pinned !== null && $startsOn !== null && $startsOn > $pinned) {
            $periodEnd = (new RentCycle($cycleDay))->end($pinned);
            if ($startsOn <= $periodEnd) {
                $date = $startsOn;
            }
        }
        $chargeable = $rate !== null && self::coversStay($startsOn, $endsOn, $date);
        $amount = $rate;
        if ($chargeable && $startsOn !== null && $rate !== null) {
            $amount = (new RentCycle($cycleDay))->amount($rate, $override, $startsOn, $date);
        }

        return ['date' => $date, 'chargeable' => $chargeable, 'amount' => $amount];
    }

    private static function chargeDate(string $month, string $today, ?string $startsOn): string
    {
        if (substr($today, 0, 7) === $month) {
            return $today;
        }
        $first = $month . '-01';
        if ($startsOn !== null && substr($startsOn, 0, 7) === $month && $startsOn > $first) {
            return $startsOn;
        }

        return $first;
    }

    private static function coversStay(?string $startsOn, ?string $endsOn, string $day): bool
    {
        if ($startsOn === null || $startsOn > $day) {
            return false;
        }

        return $endsOn === null || $endsOn >= $day;
    }
}
