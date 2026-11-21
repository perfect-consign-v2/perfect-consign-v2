<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use InvalidArgumentException;

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
            $net = $row['subtotal_minor'] - $row['discount_minor'];
            $this->merchandise($row['section'], $row['subtotal_minor'], $net, $gross, $exempt, $returns, $taxable);
            $this->collected($row['section'], $row['tax_minor'], $collected);
        }

        return [
            'gross' => $gross,
            'exempt' => $exempt,
            'returns' => $returns,
            'taxable' => $taxable,
            'collected' => $collected,
        ];
    }

    private function merchandise(
        string $section,
        int $subtotal,
        int $net,
        int &$gross,
        int &$exempt,
        int &$returns,
        int &$taxable,
    ): void {
        if ($section === 'taxable' || $section === 'exempt') {
            $gross += $subtotal;
        }
        if ($section === 'exempt') {
            $exempt += $net;
        }
        if ($section === 'taxable') {
            $taxable += $net;
        }
        if ($section === 'return' || $section === 'void') {
            $returns += -$net;
            $taxable += $net;
        }
        if ($section === 'void_exempt') {
            $returns += -$net;
            $exempt += $net;
        }
    }

    private function collected(string $section, int $tax, int &$collected): void
    {
        if (in_array($section, ['taxable', 'exempt', 'return', 'void', 'void_exempt'], true)) {
            $collected += $tax;
        }
    }

    /**
     * @param list<array{section: string, label: string, subtotal_minor: int}> $rows
     * @return list<array{label: string, base_minor: int}>
     */
    public function rates(array $rows): array
    {
        $words = new ReportWords();
        $listed = [];
        foreach ($rows as $row) {
            if ($row['section'] !== 'rate') {
                continue;
            }
            $listed[] = [
                'label' => $words->rate($row['label']),
                'base_minor' => $row['subtotal_minor'],
            ];
        }

        return $listed;
    }

    /**
     * @param list<array{section: string, label: string, total_minor: int}> $rows
     * @return array{opening: int, collected: int, paid: int, closing: int}
     */
    public function liability(array $rows): array
    {
        $found = [];
        foreach ($rows as $row) {
            if ($row['section'] === 'liability') {
                $found[$row['label']] = $row['total_minor'];
            }
        }
        foreach (['Opening', 'Collected', 'Paid', 'Closing'] as $label) {
            if (!array_key_exists($label, $found)) {
                throw new InvalidArgumentException('That liability line is not on this page.');
            }
        }

        return [
            'opening' => $found['Opening'],
            'collected' => $found['Collected'],
            'paid' => $found['Paid'],
            'closing' => $found['Closing'],
        ];
    }

    /**
     * @param list<array{section: string, label: string, sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}> $rows
     * @return list<array{section: string, label: string, sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}>
     */
    public function detail(array $rows): array
    {
        $words = new ReportWords();
        $listed = [];
        foreach ($rows as $row) {
            if ($this->blank($row)) {
                continue;
            }
            $listed[] = [
                'section' => $words->section($row['section']),
                'label' => $this->label($words, $row['section'], $row['label']),
                'sale_count' => $row['sale_count'],
                'items_sold' => $row['items_sold'],
                'subtotal_minor' => $row['subtotal_minor'],
                'discount_minor' => $row['discount_minor'],
                'tax_minor' => $row['tax_minor'],
                'total_minor' => $row['total_minor'],
            ];
        }

        return $listed;
    }

    /**
     * @param array{sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int} $row
     */
    private function blank(array $row): bool
    {
        return $row['sale_count'] === 0
            && $row['items_sold'] === 0
            && $row['subtotal_minor'] === 0
            && $row['discount_minor'] === 0
            && $row['tax_minor'] === 0
            && $row['total_minor'] === 0;
    }

    private function label(ReportWords $words, string $section, string $label): string
    {
        if ($section === 'rate') {
            return $words->rate($label);
        }
        if ($section === 'liability') {
            return $words->liability($label);
        }

        return $label;
    }
}
