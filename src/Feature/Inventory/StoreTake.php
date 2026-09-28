<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StoreTake
{
    public function fromPercent(string $percent): ?int
    {
        if ($percent === '') {
            return null;
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $percent, $parts) !== 1) {
            return null;
        }
        $fraction = str_pad($parts[2] ?? '', 2, '0');
        $basis = ((int) $parts[1] * 100) + (int) $fraction;
        if ($basis > 10000) {
            return null;
        }

        return $basis;
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
