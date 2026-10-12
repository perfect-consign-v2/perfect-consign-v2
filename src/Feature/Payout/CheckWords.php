<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use InvalidArgumentException;

final class CheckWords
{
    private const ONES = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
        'seventeen', 'eighteen', 'nineteen',
    ];

    private const TENS = [
        '', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety',
    ];

    public function words(int $minor): string
    {
        if ($minor < 0 || $minor > 99999999999) {
            throw new InvalidArgumentException('a check amount is out of range');
        }
        $cents = str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return ucwords($this->speak(intdiv($minor, 100)), " \t\r\n\f\v-") . ' and ' . $cents . '/100';
    }

    private function speak(int $dollars): string
    {
        if ($dollars === 0) {
            return 'zero';
        }
        $parts = [];
        foreach ([1000000 => 'million', 1000 => 'thousand', 1 => ''] as $scale => $name) {
            $count = intdiv($dollars, $scale);
            $dollars %= $scale;
            if ($count === 0) {
                continue;
            }
            $parts[] = trim($this->chunk($count) . ' ' . $name);
        }

        return implode(' ', $parts);
    }

    private function chunk(int $value): string
    {
        if ($value > 999) {
            throw new InvalidArgumentException('a check amount is out of range');
        }

        return trim($this->hundreds($value) . ' ' . $this->belowHundred($value % 100));
    }

    private function hundreds(int $value): string
    {
        if ($value < 100) {
            return '';
        }

        return self::ONES[intdiv($value, 100)] . ' hundred';
    }

    private function belowHundred(int $value): string
    {
        if ($value >= 20) {
            return $this->tens($value);
        }

        return self::ONES[$value];
    }

    private function tens(int $value): string
    {
        $rest = self::TENS[intdiv($value, 10)];
        $one = self::ONES[$value % 10];
        if ($one === '') {
            return $rest;
        }

        return $rest . '-' . $one;
    }
}
