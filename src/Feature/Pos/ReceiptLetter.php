<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

final class ReceiptLetter
{
    /**
     * @param array{
     *     sale_number: int,
     *     posted_on: string,
     *     voided: bool,
     *     lines: list<array{description: string, code: string, price: int, quantity: int, discount: int, tax?: int, store_discount?: int}>,
     *     tenders: list<array{name: string, amount: int}>,
     *     round: int,
     *     discount: int
     * } $receipt
     * @param list<string> $companyLines
     */
    public function body(array $receipt, string $storeName, array $companyLines, string $footer): string
    {
        $lines = [];
        foreach ($companyLines as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            $lines[] = $storeName;
        }
        $lines[] = '';
        $lines[] = 'Sale ' . (string) $receipt['sale_number'];
        $lines[] = 'Date ' . $receipt['posted_on'];
        if ($receipt['voided'] === true) {
            $lines[] = 'Voided';
        }
        $lines[] = '';
        $totals = $this->items($receipt['lines'], $lines);
        $this->totals($lines, $totals, $receipt, $footer);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     * @param array{items: int, tax: int} $totals
     * @param array{tenders: list<array{name: string, amount: int}>, round: int, discount: int} $receipt
     */
    private function totals(array &$lines, array $totals, array $receipt, string $footer): void
    {
        $discount = RowValue::integer($receipt['discount']);
        $round = RowValue::integer($receipt['round']);
        $lines[] = '';
        $lines[] = 'Subtotal  ' . View::money($totals['items']);
        if ($discount !== 0) {
            $lines[] = 'Discount  ' . View::money($discount);
        }
        if ($totals['tax'] !== 0) {
            $lines[] = 'Tax  ' . View::money($totals['tax']);
        }
        if ($round !== 0) {
            $lines[] = 'Cash round  ' . View::money($round);
        }
        $lines[] = 'Total  ' . View::money($totals['items'] - $discount + $totals['tax'] + $round);
        foreach ($receipt['tenders'] as $tender) {
            $lines[] = $tender['name'] . '  ' . View::money(RowValue::integer($tender['amount']));
        }
        if (trim($footer) !== '') {
            $lines[] = '';
            $lines[] = $footer;
        }
    }

    /**
     * @param list<array{description: string, code: string, price: int, quantity: int, discount: int, tax?: int, store_discount?: int}> $sold
     * @param list<string> $lines
     * @return array{items: int, tax: int}
     */
    private function items(array $sold, array &$lines): array
    {
        $items = 0;
        $tax = 0;
        foreach ($sold as $line) {
            $quantity = RowValue::integer($line['quantity']);
            $count = $quantity > 0 ? $quantity : 1;
            $store = RowValue::integer($line['store_discount'] ?? 0);
            $amount = RowValue::integer($line['price']) * $count - RowValue::integer($line['discount']) - $store;
            $items += RowValue::integer($line['price']) * $count;
            $tax += RowValue::integer($line['tax'] ?? 0);
            $lines[] = $line['description'] . '  ' . View::money($amount);
            $lines[] = (string) $count . '  ' . $line['code'];
        }

        return ['items' => $items, 'tax' => $tax];
    }
}
