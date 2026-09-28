<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

final class CashRound
{
    public static function adjustment(int $cents): int
    {
        return match ($cents % 5) {
            1 => -1,
            2 => -2,
            3 => 2,
            4 => 1,
            default => 0,
        };
    }

    public static function rounded(int $cents): int
    {
        return $cents + self::adjustment($cents);
    }
}
