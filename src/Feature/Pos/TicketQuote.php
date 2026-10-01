<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\BasisPoints;

final class TicketQuote
{
    public static function halfUp(int $price, int $bps): int
    {
        return BasisPoints::halfUp($price, $bps);
    }

    public static function tax(int $price, int $discount, int $taxBps): int
    {
        return BasisPoints::tax($price - $discount, $taxBps);
    }

    public static function percentLabel(int $bps): string
    {
        $whole = intdiv($bps, 100);
        $fraction = $bps % 100;
        if ($fraction === 0) {
            return $whole . '%';
        }

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) . '%';
    }
}
