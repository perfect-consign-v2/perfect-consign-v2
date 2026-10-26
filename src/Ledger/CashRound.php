<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

final class CashRound
{
    /** @var list<int> */
    public const STEPS = [1, 5, 10, 25];

    public static function allowed(int $step): bool
    {
        return $step === 0 || in_array($step, self::STEPS, true);
    }

    public static function parse(string $raw): int
    {
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        if (preg_match('/^(1|5|10|25)$/', $raw) !== 1) {
            throw new \InvalidArgumentException('Choose a cash round step.');
        }

        return (int) $raw;
    }

    public static function adjustment(int $cents, int $step): int
    {
        if ($step < 1 || $cents < 0) {
            return 0;
        }
        $rem = $cents % $step;
        if ($rem === 0) {
            return 0;
        }
        if ($rem * 2 <= $step) {
            return -$rem;
        }

        return $step - $rem;
    }

    public static function rounded(int $cents, int $step): int
    {
        return $cents + self::adjustment($cents, $step);
    }
}
