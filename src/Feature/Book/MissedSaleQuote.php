<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use InvalidArgumentException;
use PerfectConsign\Ledger\BasisPoints;

final class MissedSaleQuote
{
    public static function vendorShare(int $price, int $bps): int
    {
        if ($price < 1) {
            throw new InvalidArgumentException('a missed sale owes the vendor');
        }
        if ($bps < 0 || $bps > BasisPoints::PERCENT) {
            throw new InvalidArgumentException('a missed sale owes the vendor');
        }
        $share = $price - BasisPoints::halfUp($price, $bps);
        if ($share < 1) {
            throw new InvalidArgumentException('a missed sale owes the vendor');
        }

        return $share;
    }
}
