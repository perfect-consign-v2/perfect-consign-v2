<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class InchMeasure
{
    public static function text(int $millionths): string
    {
        $absolute = abs($millionths);
        $whole = intdiv($absolute, 1000000);
        $fraction = str_pad((string) ($absolute % 1000000), 6, '0', STR_PAD_LEFT);

        return ($millionths < 0 ? '-' : '') . $whole . '.' . $fraction . 'in';
    }
}
