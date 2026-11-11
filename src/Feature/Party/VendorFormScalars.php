<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/** Scalar display helpers for vendor forms. */
final class VendorFormScalars
{
    public function points(mixed $basis): int
    {
        if (is_int($basis)) {
            return $basis;
        }
        if (is_string($basis) && preg_match('/^\d+$/', $basis) === 1) {
            return (int) $basis;
        }

        return 0;
    }

    public function percent(mixed $basis): string
    {
        $points = $this->points($basis);
        $whole = intdiv($points, 100);
        $fraction = $points % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }
        if ($fraction % 10 === 0) {
            return $whole . '.' . (string) intdiv($fraction, 10);
        }

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    public function plain(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public function enabled(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * @return list<string>
     */
    public function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $list = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $list[] = $value;
            }
        }

        return $list;
    }
}
