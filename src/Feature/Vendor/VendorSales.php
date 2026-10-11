<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use DateTimeImmutable;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorSales
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @param array{from: string, to: string, rent: string} $window
     * @return array{from: string, to: string, label: string}
     */
    public function span(
        string $today,
        string $range,
        string $from,
        string $to,
        ?string $lastCheck,
        array $window,
    ): array {
        if ($range === 'check') {
            return ['from' => $window['from'], 'to' => $window['to'], 'label' => 'This check'];
        }
        if ($range === 'week') {
            $day = new DateTimeImmutable($today);
            $monday = $day->modify('-' . ((int) $day->format('N') - 1) . ' days')->format('Y-m-d');

            return ['from' => $monday, 'to' => $today, 'label' => 'This week'];
        }
        if ($range === 'month') {
            return ['from' => substr($today, 0, 7) . '-01', 'to' => $today, 'label' => 'This month'];
        }
        if ($range === 'custom') {
            $chosen = $this->chosen($from, $to);
            if ($chosen !== null) {
                return $chosen;
            }
        }
        $start = $window['from'];
        if ($lastCheck !== null) {
            $start = (new DateTimeImmutable($lastCheck))->modify('+1 day')->format('Y-m-d');
        }
        if ($start > $today) {
            $start = $today;
        }

        return ['from' => $start, 'to' => $today, 'label' => 'Since your last check'];
    }

    /**
     * @return array{from: string, to: string, label: string}|null
     */
    private function chosen(string $from, string $to): ?array
    {
        $start = $this->day($from);
        $end = $this->day($to);
        if ($start === null || $end === null) {
            return null;
        }
        if ($end < $start) {
            $end = $start;
        }

        return ['from' => $start, 'to' => $end, 'label' => 'Those dates'];
    }

    /**
     * @return array{rows: list<array{date: string, code: string, description: string, price: int, discount: int, sold: int, take: int, share: int}>, total: int}
     */
    public function lines(string $vendorId, string $from, string $to): array
    {
        $rows = [];
        foreach ($this->posted($vendorId, $from, $to) as $row) {
            $rows[] = $row;
        }
        usort(
            $rows,
            static function (array $left, array $right): int {
                $date = strcmp($right['date'], $left['date']);
                if ($date !== 0) {
                    return $date;
                }

                return strnatcasecmp($left['code'], $right['code']);
            }
        );
        $total = 0;
        foreach ($rows as $row) {
            $total += $row['share'];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return list<array{date: string, code: string, description: string, price: int, discount: int, sold: int, take: int, share: int}>
     */
    private function posted(string $vendorId, string $from, string $to): array
    {
        $sales = $this->statements->bound(
            "SELECT to_char(journal.posting_date, 'YYYY-MM-DD') AS posted_on,
                    item.code, item.description, sale.price_minor, sale.discount_minor,
                    sale.commission_minor, sale.quantity
             FROM sale_lines AS sale
             JOIN journals AS journal ON journal.id = sale.journal_id
             JOIN items AS item ON item.id = sale.item_id
             WHERE item.vendor_id = ?::uuid
               AND journal.source_type = 'sale'
               AND NOT journal.is_reversal
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = journal.id
               )
               AND journal.posting_date BETWEEN ?::date AND ?::date",
            [$vendorId, $from, $to]
        );
        $returns = $this->statements->bound(
            "SELECT to_char(journal.posting_date, 'YYYY-MM-DD') AS posted_on,
                    item.code, item.description, returned.price_minor, returned.discount_minor,
                    returned.commission_minor, returned.quantity
             FROM item_returns AS returned
             JOIN journals AS journal ON journal.id = returned.journal_id
             JOIN items AS item ON item.id = returned.item_id
             WHERE item.vendor_id = ?::uuid
               AND NOT journal.is_reversal
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = journal.id
               )
               AND journal.posting_date BETWEEN ?::date AND ?::date",
            [$vendorId, $from, $to]
        );
        $rows = [];
        foreach ($sales as $row) {
            $rows[] = $this->line($row, 1);
        }
        foreach ($returns as $row) {
            $rows[] = $this->line($row, -1);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{date: string, code: string, description: string, price: int, discount: int, sold: int, take: int, share: int}
     */
    private function line(array $row, int $sign): array
    {
        $price = RowValue::integer($row['price_minor'] ?? null);
        $quantity = RowValue::integer($row['quantity'] ?? null);
        $discount = RowValue::integer($row['discount_minor'] ?? null);
        $take = RowValue::integer($row['commission_minor'] ?? null);
        $sold = $price * $quantity - $discount;
        $share = $sign < 0 ? -($price - $discount - $take) : $sold - $take;

        return [
            'date' => RowValue::string($row['posted_on'] ?? null),
            'code' => RowValue::string($row['code'] ?? null),
            'description' => RowValue::string($row['description'] ?? null),
            'price' => $price,
            'discount' => $discount,
            'sold' => $sign * $sold,
            'take' => $sign * $take,
            'share' => $share,
        ];
    }

    private function day(string $value): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }
}
