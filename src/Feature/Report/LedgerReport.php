<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class LedgerReport
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{label: string, merchandise_minor: int, tax_minor: int, receipt_count: int, taken_minor: int}>
     */
    public function sales(string $from, string $to, string $grouped): array
    {
        $listed = [];
        foreach (
            $this->statements->bound(
                'SELECT label, merchandise_minor, tax_minor, receipt_count, taken_minor
                 FROM stall_report_sales(?::date, ?::date, ?)',
                [$from, $to, $grouped],
            ) as $row
        ) {
            $listed[] = [
                'label' => RowValue::string($row['label'] ?? null),
                'merchandise_minor' => RowValue::integer($row['merchandise_minor'] ?? null),
                'tax_minor' => RowValue::integer($row['tax_minor'] ?? null),
                'receipt_count' => RowValue::integer($row['receipt_count'] ?? null),
                'taken_minor' => RowValue::integer($row['taken_minor'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<array{booth: string, vendor: string, open_minor: int}>
     */
    public function rent(): array
    {
        $listed = [];
        foreach ($this->statements->rows('SELECT booth, vendor, open_minor FROM stall_report_rent()') as $row) {
            $listed[] = [
                'booth' => RowValue::string($row['booth'] ?? null),
                'vendor' => RowValue::string($row['vendor'] ?? null),
                'open_minor' => RowValue::integer($row['open_minor'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<array{paid_on: string, vendor: string, amount_minor: int, source_type: string}>
     */
    public function payouts(string $from, string $to): array
    {
        $listed = [];
        foreach (
            $this->statements->bound(
                'SELECT paid_on::text AS paid_on, vendor, amount_minor, source_type
                 FROM stall_report_payouts(?::date, ?::date)',
                [$from, $to],
            ) as $row
        ) {
            $listed[] = [
                'paid_on' => RowValue::string($row['paid_on'] ?? null),
                'vendor' => RowValue::string($row['vendor'] ?? null),
                'amount_minor' => RowValue::integer($row['amount_minor'] ?? null),
                'source_type' => RowValue::string($row['source_type'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<array{kind: string, balance_minor: int}>
     */
    public function storedValue(): array
    {
        $listed = [];
        foreach ($this->statements->rows('SELECT kind, balance_minor FROM stall_report_stored_value()') as $row) {
            $listed[] = [
                'kind' => RowValue::string($row['kind'] ?? null),
                'balance_minor' => RowValue::integer($row['balance_minor'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<array{code: string, description: string, vendor: string, expires_on: string, days_left: int, quantity: int, price_minor: int}>
     */
    public function expiring(): array
    {
        $listed = [];
        foreach (
            $this->statements->rows(
                'SELECT code, description, vendor, expires_on::text AS expires_on, days_left, quantity, price_minor
                 FROM stall_report_expiring()',
            ) as $row
        ) {
            $listed[] = [
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'vendor' => RowValue::string($row['vendor'] ?? null),
                'expires_on' => RowValue::string($row['expires_on'] ?? null),
                'days_left' => RowValue::integer($row['days_left'] ?? null),
                'quantity' => RowValue::integer($row['quantity'] ?? null),
                'price_minor' => RowValue::integer($row['price_minor'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<array{posted_on: string, code: string, description: string, vendor_name: string|null, quantity: int|null}>
     */
    public function donated(string $from, string $to): array
    {
        $listed = [];
        foreach (
            $this->statements->bound(
                'SELECT posted_on::text AS posted_on, code, description, vendor_name, quantity
                 FROM stall_report_donated(?::date, ?::date)',
                [$from, $to],
            ) as $row
        ) {
            $listed[] = [
                'posted_on' => RowValue::string($row['posted_on'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'vendor_name' => RowValue::optionalString($row['vendor_name'] ?? null),
                'quantity' => RowValue::optionalInteger($row['quantity'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<array{category: string, sold_qty: int, on_hand_qty: int, sales_minor: int, sold_percent: int|null}>
     */
    public function sellThrough(string $from, string $to): array
    {
        $listed = [];
        foreach (
            $this->statements->bound(
                'SELECT category, sold_qty, on_hand_qty, sales_minor, sold_percent
                 FROM stall_report_sell_through(?::date, ?::date)',
                [$from, $to],
            ) as $row
        ) {
            $listed[] = [
                'category' => RowValue::string($row['category'] ?? null),
                'sold_qty' => RowValue::integer($row['sold_qty'] ?? null),
                'on_hand_qty' => RowValue::integer($row['on_hand_qty'] ?? null),
                'sales_minor' => RowValue::integer($row['sales_minor'] ?? null),
                'sold_percent' => RowValue::optionalInteger($row['sold_percent'] ?? null),
            ];
        }

        return $listed;
    }
}
