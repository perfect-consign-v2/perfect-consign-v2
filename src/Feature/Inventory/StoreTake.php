<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Ledger\BasisPoints;

final class StoreTake
{
    public function fromPercent(string $percent): ?int
    {
        if ($percent === '') {
            return null;
        }

        return BasisPoints::percent($percent);
    }

    public function format(int $basis): string
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
