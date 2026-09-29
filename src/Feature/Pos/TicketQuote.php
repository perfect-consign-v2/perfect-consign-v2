<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class TicketQuote
{
    public static function halfUp(int $price, int $bps): int
    {
        return intdiv($price * $bps + 5000, 10000);
    }

    public static function tax(int $price, int $discount, int $taxBps): int
    {
        return intdiv(($price - $discount) * $taxBps + 50000, 100000);
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

    /**
     * @param list<int> $prices
     * @return list<int>
     */
    public static function shares(array $prices, ?string $kind, ?int $value): array
    {
        $shares = array_fill(0, count($prices), 0);
        if ($kind === null || $value === null || $value === 0 || $prices === []) {
            return $shares;
        }
        if ($kind === 'percent') {
            return self::percentShares($prices, $value);
        }
        if ($kind !== 'amount') {
            return $shares;
        }

        return self::amountShares($prices, $value);
    }

    /**
     * @param list<int> $prices
     * @return list<int>
     */
    private static function percentShares(array $prices, int $value): array
    {
        $shares = [];
        foreach ($prices as $price) {
            $shares[] = self::halfUp($price, $value);
        }

        return $shares;
    }

    /**
     * @param list<int> $prices
     * @return list<int>
     */
    private static function amountShares(array $prices, int $value): array
    {
        $total = 0;
        foreach ($prices as $price) {
            $total += $price;
        }
        if ($total === 0) {
            return array_fill(0, count($prices), 0);
        }
        $shares = [];
        $running = 0;
        $last = count($prices) - 1;
        foreach ($prices as $index => $price) {
            if ($index === $last) {
                $shares[] = $value - $running;
                continue;
            }
            $share = intdiv($price * $value + intdiv($total, 2), $total);
            $shares[] = $share;
            $running += $share;
        }

        return $shares;
    }
}
