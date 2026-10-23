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
     * @return list<array{label: string, sales_minor: int, tax_minor: int, sale_count: int}>
     */
    public function sales(string $from, string $to, string $grouped): array
    {
        $listed = [];
        foreach ($this->statements->bound(
            'SELECT label, sales_minor, tax_minor, sale_count FROM stall_report_sales(?::date, ?::date, ?)',
            [$from, $to, $grouped]
        ) as $row) {
            $listed[] = [
                'label' => RowValue::string($row['label'] ?? null),
                'sales_minor' => RowValue::integer($row['sales_minor'] ?? null),
                'tax_minor' => RowValue::integer($row['tax_minor'] ?? null),
                'sale_count' => RowValue::integer($row['sale_count'] ?? null),
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
     * @return list<array{paid_on: string, vendor: string, amount_minor: int, kind: string}>
     */
    public function payouts(string $from, string $to): array
    {
        $listed = [];
        foreach ($this->statements->bound(
            'SELECT paid_on::text AS paid_on, vendor, amount_minor, kind FROM stall_report_payouts(?::date, ?::date)',
            [$from, $to]
        ) as $row) {
            $listed[] = [
                'paid_on' => RowValue::string($row['paid_on'] ?? null),
                'vendor' => RowValue::string($row['vendor'] ?? null),
                'amount_minor' => RowValue::integer($row['amount_minor'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
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
}
