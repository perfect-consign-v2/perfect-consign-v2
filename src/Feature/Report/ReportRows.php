<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use InvalidArgumentException;

final class ReportRows
{
    public function __construct(private readonly ReportWords $words = new ReportWords())
    {
    }

    /**
     * @param list<array{label: string, merchandise_minor: int, tax_minor: int, receipt_count: int, taken_minor: int}> $rows
     */
    public function sales(string $group, array $rows): ReportGrid
    {
        if ($group === 'tender') {
            return $this->tender($rows);
        }
        $heading = match ($group) {
            'day' => 'Day',
            'vendor' => 'Vendor',
            'cashier' => 'Cashier',
            'hour' => 'Hour',
            'category' => 'Category',
            default => throw new InvalidArgumentException('That grouping is not on this page.'),
        };

        return new ReportGrid(
            [
                new ReportColumn('label', $heading, $group === 'day' ? 'date' : 'text'),
                new ReportColumn('merchandise_minor', 'Sales', 'money'),
                new ReportColumn('tax_minor', 'Tax', 'money'),
                new ReportColumn('receipt_count', 'Receipts', 'number'),
            ],
            $rows,
        );
    }

    /**
     * @param list<array{paid_on: string, vendor: string, amount_minor: int, source_type: string}> $rows
     */
    public function payouts(array $rows): ReportGrid
    {
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'paid_on' => $row['paid_on'],
                'vendor' => $row['vendor'],
                'amount_minor' => $row['amount_minor'],
                'how' => $this->words->payment($row['source_type']),
            ];
        }

        return new ReportGrid(
            [
                new ReportColumn('paid_on', 'Date', 'date'),
                new ReportColumn('vendor', 'Vendor', 'text'),
                new ReportColumn('amount_minor', 'Amount', 'money'),
                new ReportColumn('how', 'How paid', 'text'),
            ],
            $listed,
        );
    }

    /**
     * @param list<array{booth: string, vendor: string, open_minor: int}> $rows
     */
    public function rent(array $rows): ReportGrid
    {
        return new ReportGrid(
            [
                new ReportColumn('booth', 'Booth', 'text'),
                new ReportColumn('vendor', 'Vendor', 'text'),
                new ReportColumn('open_minor', 'Open', 'money'),
            ],
            $rows,
        );
    }

    /**
     * @param list<array{kind: string, balance_minor: int}> $rows
     */
    public function stored(array $rows): ReportGrid
    {
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'kind' => $this->words->stored($row['kind']),
                'balance_minor' => $row['balance_minor'],
            ];
        }

        return new ReportGrid(
            [
                new ReportColumn('kind', 'Kind', 'text'),
                new ReportColumn('balance_minor', 'Balance', 'money'),
            ],
            $listed,
        );
    }

    /**
     * @param list<array{code: string, description: string, vendor: string, expires_on: string, days_left: int, quantity: int, price_minor: int}> $rows
     */
    public function expiring(array $rows): ReportGrid
    {
        return new ReportGrid(
            [
                new ReportColumn('code', 'Tag', 'text'),
                new ReportColumn('description', 'Description', 'text'),
                new ReportColumn('vendor', 'Vendor', 'text'),
                new ReportColumn('expires_on', 'Expires', 'date'),
                new ReportColumn('days_left', 'Days left', 'number'),
                new ReportColumn('price_minor', 'Price', 'money'),
            ],
            $rows,
        );
    }

    /**
     * @param list<array{posted_on: string, code: string, description: string, vendor_name: string|null, quantity: int|null}> $rows
     */
    public function donated(array $rows): ReportGrid
    {
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'posted_on' => $row['posted_on'],
                'code' => $row['code'],
                'description' => $row['description'],
                'vendor_name' => $this->words->recorded($row['vendor_name']),
                'quantity' => $row['quantity'],
            ];
        }

        return new ReportGrid(
            [
                new ReportColumn('posted_on', 'Date', 'date'),
                new ReportColumn('code', 'Tag', 'text'),
                new ReportColumn('description', 'Description', 'text'),
                new ReportColumn('vendor_name', 'Vendor', 'text'),
                new ReportColumn('quantity', 'Quantity', 'number'),
            ],
            $listed,
        );
    }

    /**
     * @param list<array{category: string, sold_qty: int, on_hand_qty: int, sales_minor: int, sold_percent: int|null}> $rows
     */
    public function sellThrough(array $rows): ReportGrid
    {
        return new ReportGrid(
            [
                new ReportColumn('category', 'Category', 'text'),
                new ReportColumn('sold_qty', 'Sold', 'number'),
                new ReportColumn('sales_minor', 'Sales', 'money'),
                new ReportColumn('on_hand_qty', 'On hand', 'number'),
                new ReportColumn('sold_percent', 'Sold %', 'percent'),
            ],
            $rows,
        );
    }

    /**
     * @param list<array{section: string, label: string, sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}> $rows
     */
    public function tax(array $rows): ReportGrid
    {
        return new ReportGrid(
            [
                new ReportColumn('section', 'Section', 'text'),
                new ReportColumn('label', 'Label', 'text'),
                new ReportColumn('sale_count', 'Sales', 'number'),
                new ReportColumn('items_sold', 'Items', 'number'),
                new ReportColumn('subtotal_minor', 'Subtotal', 'money'),
                new ReportColumn('discount_minor', 'Discount', 'money'),
                new ReportColumn('tax_minor', 'Tax', 'money'),
                new ReportColumn('total_minor', 'Total', 'money'),
            ],
            $rows,
        );
    }

    /**
     * @param list<array{label: string, merchandise_minor: int, tax_minor: int, receipt_count: int, taken_minor: int}> $rows
     */
    private function tender(array $rows): ReportGrid
    {
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'label' => $this->words->tender($row['label']),
                'taken_minor' => $row['taken_minor'],
            ];
        }

        return new ReportGrid(
            [
                new ReportColumn('label', 'Tender', 'text'),
                new ReportColumn('taken_minor', 'Amount', 'money'),
            ],
            $listed,
        );
    }
}
