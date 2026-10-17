<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeZone;
use PerfectConsign\Ledger\BasisPoints;

final class MallMeasure
{
    /**
     * @return list<string>
     */
    public static function timeZones(string $current): array
    {
        $zones = array_values(array_unique([
            ...timezone_identifiers_list(DateTimeZone::PER_COUNTRY, 'US'),
            ...timezone_identifiers_list(DateTimeZone::PER_COUNTRY, 'CA'),
        ]));
        sort($zones, SORT_STRING);
        if ($current !== '' && !in_array($current, $zones, true)) {
            array_unshift($zones, $current);
        }

        return $zones;
    }

    public static function feet(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    public static function wholeDays(string $value): int|false|null
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,2}$/', $value) !== 1) {
            return false;
        }
        $days = (int) $value;
        if ($days > 365) {
            return false;
        }

        return $days;
    }

    public static function dayText(?int $days): string
    {
        return $days === null ? '' : (string) $days;
    }

    public static function basisPoints(string $percent): ?int
    {
        if ($percent === '') {
            return 0;
        }

        return BasisPoints::percent($percent);
    }

    public static function taxMillis(string $percent): ?int
    {
        if ($percent === '') {
            return 0;
        }

        return BasisPoints::taxRate($percent);
    }

    public static function taxPercent(int $millis): string
    {
        $whole = intdiv($millis, 1000);
        $fraction = $millis % 1000;
        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole . '.' . rtrim(str_pad((string) $fraction, 3, '0', STR_PAD_LEFT), '0');
    }

    public static function percent(int $basis): string
    {
        $whole = intdiv($basis, 100);
        $fraction = $basis % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }
        if ($fraction % 10 === 0) {
            return $whole . '.' . (string) intdiv($fraction, 10);
        }

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
