<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Scalar;

use InvalidArgumentException;

final class RowValue
{
    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('expected a string');
        }

        return $value;
    }

    public static function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::string($value);
    }

    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('expected an integer');
    }
}
