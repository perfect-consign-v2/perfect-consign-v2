<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use InvalidArgumentException;
use PerfectConsign\Shared\Http\Request;

final class DenominationCount
{
    /** @var list<int> */
    public const KEYS = [10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1];

    /** @var array<int, string> */
    public const LABELS = [
        10000 => '$100',
        5000 => '$50',
        2000 => '$20',
        1000 => '$10',
        500 => '$5',
        100 => '$1',
        25 => '25¢',
        10 => '10¢',
        5 => '5¢',
        1 => '1¢',
    ];

    /**
     * @return array<int, int>
     */
    public static function fromRequest(Request $request): array
    {
        $counts = [];
        foreach (self::KEYS as $key) {
            $raw = trim($request->field('count_' . $key));
            if (preg_match('/^\d+$/', $raw) !== 1) {
                throw new InvalidArgumentException('every bill and coin count is required');
            }
            $counts[$key] = (int) $raw;
        }

        return $counts;
    }

    /**
     * @return array<int, int>
     */
    public static function forAmount(int $minor): array
    {
        $counts = [];
        $left = $minor;
        foreach (self::KEYS as $key) {
            $counts[$key] = intdiv($left, $key);
            $left %= $key;
        }

        return $counts;
    }

    /**
     * @param array<int, int> $counts
     */
    public static function total(array $counts): int
    {
        $total = 0;
        foreach (self::KEYS as $key) {
            $total += $key * ($counts[$key] ?? 0);
        }

        return $total;
    }

    /**
     * @param array<int, int> $counts
     */
    public static function json(array $counts): string
    {
        $encoded = json_encode($counts, JSON_THROW_ON_ERROR);

        return $encoded;
    }

    public static function counted(string $raw): int
    {
        $value = trim($raw);
        if ($value === '') {
            return 0;
        }

        return self::money($value, true);
    }

    public static function money(string $amount, bool $allowZero): int
    {
        $amount = str_replace(['$', ',', ' '], '', trim($amount));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('enter an amount in dollars and cents');
        }
        $cents = str_pad($matches[2] ?? '', 2, '0');
        $minor = ((int) $matches[1] * 100) + (int) $cents;
        if ($minor < 0 || (!$allowZero && $minor < 1)) {
            throw new InvalidArgumentException('the amount must be greater than zero');
        }

        return $minor;
    }

    public static function dollars(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign . '$' . number_format($minor / 100, 2, '.', '');
    }
}
