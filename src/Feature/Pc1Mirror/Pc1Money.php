<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

final class Pc1Money
{
    public static function dollarsToCents(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^-?\d+(\.\d{1,4})?$/', $trimmed) !== 1) {
            throw new MirrorException('PC1 money value is not a decimal amount');
        }
        $negative = str_starts_with($trimmed, '-');
        if ($negative) {
            $trimmed = substr($trimmed, 1);
        }
        if (!str_contains($trimmed, '.')) {
            $cents = (int) $trimmed * 100;
        } else {
            [$whole, $frac] = explode('.', $trimmed, 2);
            $frac = str_pad(substr($frac, 0, 2), 2, '0');
            $cents = ((int) $whole * 100) + (int) $frac;
        }

        return $negative ? -$cents : $cents;
    }
}
