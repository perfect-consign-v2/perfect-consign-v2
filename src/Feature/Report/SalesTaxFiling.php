<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class SalesTaxFiling
{
    /**
     * @param list<array{section: string, label: string, subtotal_minor: int, discount_minor: int, tax_minor: int}> $rows
     * @return array{gross: int, exempt: int, returns: int, taxable: int, collected: int}
     */
    public function filing(array $rows): array
    {
        $gross = 0;
        $exempt = 0;
        $returns = 0;
        $taxable = 0;
        $collected = 0;
        foreach ($rows as $row) {
            if ($row['section'] === 'taxable' || $row['section'] === 'exempt') {
                $gross += $row['subtotal_minor'];
            }
            if ($row['section'] === 'exempt') {
                $exempt += $row['subtotal_minor'] - $row['discount_minor'];
            }
            if ($row['section'] === 'taxable') {
                $taxable += $row['subtotal_minor'] - $row['discount_minor'];
            }
            if ($row['section'] === 'return' || $row['section'] === 'void') {
                $returns += -($row['subtotal_minor'] - $row['discount_minor']);
                $taxable += $row['subtotal_minor'] - $row['discount_minor'];
            }
            if (in_array($row['section'], ['taxable', 'exempt', 'return', 'void'], true)) {
                $collected += $row['tax_minor'];
            }
        }

        return [
            'gross' => $gross,
            'exempt' => $exempt,
            'returns' => $returns,
            'taxable' => $taxable,
            'collected' => $collected,
        ];
    }
}
