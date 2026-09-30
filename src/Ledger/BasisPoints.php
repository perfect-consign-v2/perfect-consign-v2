<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

final class BasisPoints
{
    public const PERCENT = 10000;

    public const TAX = 100000;

    public static function percent(string $text): ?int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            return null;
        }
        $fraction = str_pad($parts[2] ?? '', 2, '0');
        $basis = ((int) $parts[1] * 100) + (int) $fraction;
        if ($basis > self::PERCENT) {
            return null;
        }

        return $basis;
    }

    public static function taxRate(string $text): ?int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,3}))?$/', $text, $parts) !== 1) {
            return null;
        }
        $fraction = str_pad($parts[2] ?? '', 3, '0');
        $millis = ((int) $parts[1] * 1000) + (int) $fraction;
        if ($millis > self::TAX) {
            return null;
        }

        return $millis;
    }

    public static function halfUp(int $amount, int $bps): int
    {
        return intdiv($amount * $bps + 5000, self::PERCENT);
    }

    public static function tax(int $net, int $taxBps): int
    {
        return intdiv($net * $taxBps + 50000, self::TAX);
    }
}
