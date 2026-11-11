<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Scalar;

final class PhoneFormat
{
    public static function display(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        $digits = self::nationalDigits($trimmed);
        if (strlen($digits) !== 10) {
            return $trimmed;
        }

        return '(' . substr($digits, 0, 3) . ') '
            . substr($digits, 3, 3) . '-'
            . substr($digits, 6);
    }

    /**
     * Canonical form for storage: (xxx) xxx-xxxx when ten US digits, else trimmed text.
     */
    public static function normalize(string $raw): string
    {
        return self::display($raw);
    }

    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    public static function nationalDigits(string $raw): string
    {
        $digits = self::digits($raw);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    public static function telHref(string $raw): string
    {
        $digits = self::digits($raw);
        if ($digits === '') {
            return '';
        }

        return 'tel:' . $digits;
    }
}
