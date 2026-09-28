<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorValues
{
    public function requireDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('today is YYYY-MM-DD');
        }
    }

    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new InvalidArgumentException('an assignment date is YYYY-MM-DD');
        }

        return substr($value, 0, 10);
    }

    public function standing(mixed $value): bool
    {
        if ($this->affirmative($value)) {
            return true;
        }
        if ($value === false || $value === 'f' || $value === 'false' || $value === '0') {
            return false;
        }

        throw new InvalidArgumentException('a vendor field is missing');
    }

    public function optional(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('a vendor field is missing');
        }

        return $value;
    }

    public function minor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return RowValue::integer($value);
    }

    public function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('a vendor field is missing');
        }

        return $value;
    }

    private function affirmative(mixed $value): bool
    {
        return $value === null || $value === true || $value === 't' || $value === 'true' || $value === '1';
    }
}
