<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

final class VendorLetter
{
    public static function body(string $name, int $payable, int $rent): string
    {
        return $name . "\nPayable " . self::money($payable) . "\nRent you owe " . self::money($rent) . "\n";
    }

    public static function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign . intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
