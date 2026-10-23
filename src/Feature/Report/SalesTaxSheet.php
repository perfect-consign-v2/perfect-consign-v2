<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SalesTaxSheet
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{section: string, label: string, sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}>
     */
    public function sheet(string $from, string $to): array
    {
        $listed = [];
        foreach ($this->statements->bound(
            'SELECT section, label, sale_count, items_sold, subtotal_minor, discount_minor, tax_minor, total_minor
             FROM stall_sales_tax_sheet(?::date, ?::date)',
            [$from, $to]
        ) as $row) {
            $listed[] = [
                'section' => RowValue::string($row['section'] ?? null),
                'label' => RowValue::string($row['label'] ?? null),
                'sale_count' => RowValue::integer($row['sale_count'] ?? null),
                'items_sold' => RowValue::integer($row['items_sold'] ?? null),
                'subtotal_minor' => RowValue::integer($row['subtotal_minor'] ?? null),
                'discount_minor' => RowValue::integer($row['discount_minor'] ?? null),
                'tax_minor' => RowValue::integer($row['tax_minor'] ?? null),
                'total_minor' => RowValue::integer($row['total_minor'] ?? null),
            ];
        }

        return $listed;
    }
}
