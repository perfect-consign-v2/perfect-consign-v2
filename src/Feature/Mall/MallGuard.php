<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeZone;
use PerfectConsign\Ledger\BasisPoints;

final class MallGuard
{
    public static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    public static function requireZone(string $timeZone): void
    {
        if (!in_array($timeZone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('that time zone is not an IANA name');
        }
    }

    public static function requireFeet(int $width, int $depth, string $message): void
    {
        if ($width < 1 || $depth < 1) {
            throw new \InvalidArgumentException($message);
        }
    }

    public static function requireTake(int $storeTake): void
    {
        if ($storeTake < 0 || $storeTake > BasisPoints::PERCENT) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
    }

    public static function requireTax(int $taxRate): void
    {
        if ($taxRate < 0 || $taxRate > BasisPoints::TAX) {
            throw new \InvalidArgumentException('tax rate is a percent from 0 to 100');
        }
    }

    public static function guardDays(?int $floorDays): void
    {
        if ($floorDays !== null && ($floorDays < 1 || $floorDays > 365)) {
            throw new \InvalidArgumentException('days on the floor is a whole number from 1 to 365, or empty');
        }
    }
}
