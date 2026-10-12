<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Ledger\Money;

final class RentPayment
{
    /**
     * Cash, card, and check are the payment. A blank way still uses the amount field.
     *
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     * @return array{minor: int, tender: array{cash: string, card: string, checks: string, tendered: string, check: string}}
     */
    public function apply(array $tender, string $amount, int $owed): array
    {
        $way = trim($tender['way'] ?? '');
        if ($way === 'cash') {
            return $this->cash($tender['tendered'], $tender['cash'], $owed);
        }
        if ($way === 'card') {
            return $this->card($tender['card']);
        }
        if ($way === 'check') {
            return $this->check($tender['checks'], $tender['check']);
        }
        if ($way === 'split') {
            return $this->split($tender, $owed);
        }

        return [
            'minor' => Money::parseMinor($amount),
            'tender' => [
                'cash' => $tender['cash'],
                'card' => $tender['card'],
                'checks' => $tender['checks'],
                'tendered' => $tender['tendered'],
                'check' => $tender['check'],
            ],
        ];
    }

    /**
     * Payments come off rent first. What is left of a late fee stays in what they owe.
     *
     * @return array{rent: int, late: int}
     */
    public function parts(int $owed, int $lateCharged): array
    {
        if ($owed < 1 || $lateCharged < 1) {
            return ['rent' => max(0, $owed), 'late' => 0];
        }
        $late = min($lateCharged, $owed);

        return ['rent' => $owed - $late, 'late' => $late];
    }

    /**
     * @return array{minor: int, tender: array{cash: string, card: string, checks: string, tendered: string, check: string}}
     */
    private function cash(string $tendered, string $cash, int $owed): array
    {
        $handed = $this->minor($tendered !== '' ? $tendered : $cash);
        $pay = min($handed, max(0, $owed));

        return [
            'minor' => $pay,
            'tender' => [
                'cash' => $this->dollars($pay),
                'card' => '0.00',
                'checks' => '0.00',
                'tendered' => $this->dollars($handed),
                'check' => '',
            ],
        ];
    }

    /**
     * @return array{minor: int, tender: array{cash: string, card: string, checks: string, tendered: string, check: string}}
     */
    private function card(string $card): array
    {
        return [
            'minor' => $this->minor($card),
            'tender' => [
                'cash' => '0.00',
                'card' => $this->dollars($this->minor($card)),
                'checks' => '0.00',
                'tendered' => '',
                'check' => '',
            ],
        ];
    }

    /**
     * @return array{minor: int, tender: array{cash: string, card: string, checks: string, tendered: string, check: string}}
     */
    private function check(string $checks, string $number): array
    {
        return [
            'minor' => $this->minor($checks),
            'tender' => [
                'cash' => '0.00',
                'card' => '0.00',
                'checks' => $this->dollars($this->minor($checks)),
                'tendered' => '',
                'check' => trim($number),
            ],
        ];
    }

    /**
     * Cash with no separate tendered amount is the bills they handed over.
     * The cash that pays the rent stops at what is still owed after card and check.
     *
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     * @return array{minor: int, tender: array{cash: string, card: string, checks: string, tendered: string, check: string}}
     */
    private function split(array $tender, int $owed): array
    {
        $bills = $this->minor($tender['cash']);
        $card = $this->minor($tender['card']);
        $checks = $this->minor($tender['checks']);
        if (trim($tender['tendered']) !== '') {
            return [
                'minor' => $bills + $card + $checks,
                'tender' => [
                    'cash' => $this->dollars($bills),
                    'card' => $this->dollars($card),
                    'checks' => $this->dollars($checks),
                    'tendered' => $tender['tendered'],
                    'check' => trim($tender['check']),
                ],
            ];
        }
        $exact = $card + $checks;
        $over = $exact > $owed;
        $applied = $over ? 0 : min($bills, max(0, $owed - $exact));

        return [
            'minor' => $applied + $exact,
            'tender' => [
                'cash' => $this->dollars($applied),
                'card' => $this->dollars($card),
                'checks' => $this->dollars($checks),
                'tendered' => !$over && $bills > $applied ? $this->dollars($bills) : '',
                'check' => trim($tender['check']),
            ],
        ];
    }

    private function dollars(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function minor(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0' || $raw === '0.0' || $raw === '0.00') {
            return 0;
        }

        return Money::parseMinor($raw);
    }
}
