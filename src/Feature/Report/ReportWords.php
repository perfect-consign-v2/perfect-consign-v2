<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use InvalidArgumentException;

final class ReportWords
{
    public function tender(string $role): string
    {
        return match ($role) {
            'cash' => 'Cash',
            'card' => 'Card',
            'checks' => 'Check',
            'gift_certificate' => 'Gift certificate',
            'gift_card' => 'Gift card',
            'store_credit' => 'Store credit',
            default => throw new InvalidArgumentException('That tender is not on this page.'),
        };
    }

    public function payment(string $source): string
    {
        return match ($source) {
            'check_disbursement' => 'Check',
            'cash_disbursement' => 'Cash',
            'safe_disbursement' => 'From the safe',
            'settlement' => 'Settled against rent',
            default => throw new InvalidArgumentException('That payment is not on this page.'),
        };
    }

    public function stored(string $role): string
    {
        return match ($role) {
            'gift_card' => 'Gift card',
            'gift_certificate' => 'Gift certificate',
            'store_credit' => 'Store credit',
            default => throw new InvalidArgumentException('That stored value is not on this page.'),
        };
    }

    public function rate(string $basisPoints): string
    {
        if (preg_match('/^-?\d+$/', $basisPoints) !== 1) {
            throw new InvalidArgumentException('That rate is not on this page.');
        }

        return number_format(((int) $basisPoints) / 1000, 2, '.', '') . '%';
    }

    public function section(string $section): string
    {
        return match ($section) {
            'taxable' => 'Taxable',
            'exempt' => 'Exempt',
            'return' => 'Returns',
            'void' => 'Voids',
            'void_exempt' => 'Exempt voids',
            'rate' => 'Rate',
            'nontaxable' => 'Not taxable',
            'liability' => 'Liability',
            default => throw new InvalidArgumentException('That section is not on this page.'),
        };
    }

    public function liability(string $label): string
    {
        return match ($label) {
            'Opening' => 'Started the month',
            'Collected' => 'Collected',
            'Paid' => 'Paid to the state',
            'Closing' => 'Still owe',
            default => throw new InvalidArgumentException('That liability line is not on this page.'),
        };
    }

    public function recorded(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Not recorded';
        }

        return $value;
    }
}
