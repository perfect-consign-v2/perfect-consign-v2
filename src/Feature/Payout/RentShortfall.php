<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Feature\Party\MoveInRent;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

final class RentShortfall
{
    private const DAY_WORDS = [
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
        20 => 'Twenty',
        21 => 'Twenty-one',
        22 => 'Twenty-two',
        23 => 'Twenty-three',
        24 => 'Twenty-four',
        25 => 'Twenty-five',
        26 => 'Twenty-six',
        27 => 'Twenty-seven',
        28 => 'Twenty-eight',
        29 => 'Twenty-nine',
        30 => 'Thirty',
        31 => 'Thirty-one',
    ];

    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @param list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int}> $pack
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}>
     */
    public function attach(array $pack, string $rentMonth): array
    {
        $stories = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rentMonth) === 1 ? $this->facts($rentMonth) : [];
        $noted = [];
        foreach ($pack as $row) {
            $row['note'] = $this->note($row['owes'], $row['monthRent'], $row['payable'], $stories[$row['vendor_id']] ?? []);
            $noted[] = $row;
        }

        return $noted;
    }

    public static function sentence(string $month, string $booth, int $days, int $charged, int $paid, int $unpaid): string
    {
        $still = 'Still unpaid from ' . $month . ': ' . View::money($unpaid) . '.';
        $place = $booth === '' ? '' : ' at ' . $booth;
        if ($days > 1) {
            $word = self::DAY_WORDS[$days] ?? (string) $days;
            $detail = $word . ' days' . $place . ' was ' . View::money($charged);
        } else {
            $detail = $month . ' rent' . $place . ' was ' . View::money($charged);
        }
        if ($paid > 0) {
            $detail .= ', and ' . View::money($paid) . ' was paid';
        }

        return $still . ' ' . $detail . '.';
    }

    /**
     * @param list<array{month: string, booth: string, days: int, charged: int, paid: int, unpaid: int}> $stories
     */
    private function note(int $owes, int $monthRent, int $payable, array $stories): string
    {
        $gap = $owes - max($monthRent - $payable, 0);
        if ($gap <= 0) {
            return '';
        }
        $parts = [];
        $sum = 0;
        foreach ($stories as $story) {
            $sum += $story['unpaid'];
            $parts[] = self::sentence(
                $story['month'],
                $story['booth'],
                $story['days'],
                $story['charged'],
                $story['paid'],
                $story['unpaid'],
            );
        }
        if ($parts !== [] && $sum === $gap) {
            return implode(' ', $parts);
        }

        return 'Still unpaid from an earlier month: ' . View::money($gap) . '.';
    }

    /**
     * @return array<string, list<array{month: string, booth: string, days: int, charged: int, paid: int, unpaid: int}>>
     */
    private function facts(string $rentMonth): array
    {
        $paid = [];
        foreach ($this->payments($rentMonth) as $row) {
            $key = RowValue::string($row['vendor_id'] ?? null)
                . '|' . RowValue::string($row['booth'] ?? null)
                . '|' . RowValue::string($row['month'] ?? null);
            $paid[$key] = RowValue::integer($row['paid'] ?? null);
        }
        $stories = [];
        foreach ($this->charges($rentMonth) as $row) {
            $vendorId = RowValue::string($row['vendor_id'] ?? null);
            $booth = RowValue::string($row['booth'] ?? null);
            $posted = RowValue::string($row['posted'] ?? null);
            $charged = RowValue::integer($row['charged'] ?? null);
            $monthKey = substr($posted, 0, 7);
            $key = $vendorId . '|' . $booth . '|' . $monthKey;
            $payment = $paid[$key] ?? 0;
            $used = min($payment, $charged);
            $paid[$key] = $payment - $used;
            $unpaid = $charged - $used;
            if ($unpaid <= 0) {
                continue;
            }
            $stories[$vendorId] ??= [];
            $stories[$vendorId][] = [
                'month' => (new \DateTimeImmutable($posted))->format('F'),
                'booth' => $booth,
                'days' => $this->days($row['starts_on'] ?? null, $row['monthly'] ?? null, $posted, $charged),
                'charged' => $charged,
                'paid' => $used,
                'unpaid' => $unpaid,
            ];
        }

        return $stories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payments(string $rentMonth): array
    {
        return $this->statements->bound(
            "SELECT vendor.id AS vendor_id, booth.code AS booth,
                    to_char(journal.posting_date, 'YYYY-MM') AS month,
                    sum(line.credit_minor - line.debit_minor) AS paid
             FROM journals AS journal
             JOIN journal_lines AS line ON line.journal_id = journal.id
             JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
             JOIN booths AS booth ON booth.id::text = line.subledger_ref
             JOIN booth_assignments AS assignment
               ON assignment.booth_id = booth.id
              AND assignment.assignment_range @> journal.posting_date
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             WHERE journal.source_type IN ('rent_payment', 'settlement')
               AND NOT journal.is_reversal
               AND line.credit_minor > line.debit_minor
               AND journal.posting_date < ?::date
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = journal.id
               )
             GROUP BY vendor.id, booth.code, to_char(journal.posting_date, 'YYYY-MM')",
            [$rentMonth]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function charges(string $rentMonth): array
    {
        return $this->statements->bound(
            "SELECT vendor.id AS vendor_id, booth.code AS booth,
                    to_char(journal.posting_date, 'YYYY-MM-DD') AS posted,
                    to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                    rate.amount_minor AS monthly,
                    line.debit_minor AS charged
             FROM journals AS journal
             JOIN journal_lines AS line ON line.journal_id = journal.id
             JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
             JOIN booths AS booth ON booth.id::text = line.subledger_ref
             JOIN booth_assignments AS assignment
               ON assignment.booth_id = booth.id
              AND assignment.assignment_range @> journal.posting_date
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             JOIN assignment_rent AS rate ON rate.assignment_id = assignment.id
             WHERE journal.source_type = 'rent_charge'
               AND NOT journal.is_reversal
               AND line.debit_minor > 0
               AND journal.posting_date < ?::date
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = journal.id
               )
             ORDER BY journal.posting_date, booth.code",
            [$rentMonth]
        );
    }

    private function days(mixed $starts, mixed $monthly, string $posted, int $charged): int
    {
        if (!is_string($starts) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) !== 1) {
            return 0;
        }
        if (substr($starts, 0, 7) !== substr($posted, 0, 7)) {
            return 0;
        }
        $rate = RowValue::integer($monthly);
        if (MoveInRent::due($rate, $starts) !== $charged) {
            return 0;
        }
        $day = (int) substr($starts, 8, 2);
        if ($day <= 1) {
            return 0;
        }
        $daysInMonth = (int) (new \DateTimeImmutable(substr($starts, 0, 8) . '01'))->format('t');

        return $daysInMonth - $day + 1;
    }
}
