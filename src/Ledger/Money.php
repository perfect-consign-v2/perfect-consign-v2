<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use InvalidArgumentException;

final class Money
{
    public static function parseMinor(string $amount): int
    {
        $amount = str_replace(['$', ',', ' '], '', trim($amount));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('enter an amount in dollars and cents');
        }
        $cents = $matches[2] ?? '';
        $cents = str_pad($cents, 2, '0');
        if (strlen($matches[1]) > 9 || (int) $matches[1] > intdiv(PHP_INT_MAX - 99, 100)) {
            throw new InvalidArgumentException('the amount is too large');
        }
        $minor = ((int) $matches[1] * 100) + (int) $cents;
        if ($minor < 1) {
            throw new InvalidArgumentException('the amount must be greater than zero');
        }

        return $minor;
    }
}
