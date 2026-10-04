<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Feature\Party\MoveInRent;

/**
 * The charge date and the amount the ledger will accept for one booth in one month.
 */
final class RentFigure
{
    /**
     * @return array{date: string, chargeable: bool, amount: ?int}
     */
    public static function posted(?int $rate, ?string $startsOn, ?string $endsOn, ?int $override, string $month, string $today): array
    {
        $date = self::chargeDate($month, $today, $startsOn);
        $chargeable = $rate !== null && self::coversStay($startsOn, $endsOn, $date);
        $amount = $rate;
        if ($chargeable && $startsOn !== null && $rate !== null) {
            $amount = MoveInRent::charge($rate, $override, $startsOn, $month);
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
