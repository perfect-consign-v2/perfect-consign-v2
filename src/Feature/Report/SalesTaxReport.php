<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Request;

final class SalesTaxReport
{
    private readonly ReportSpan $span;

    private readonly ReportRows $rows;

    private readonly ReportTable $table;

    public function __construct(
        private readonly SalesTaxSheet $sheet,
        private readonly SalesTaxFiling $filing,
        private readonly ReportTie $tie,
    ) {
        $this->span = new ReportSpan();
        $this->rows = new ReportRows();
        $this->table = new ReportTable();
    }

    public function page(Request $request, string $today): ReportPage
    {
        $window = $this->span->month($request, $today);
        if ($window->isFailed()) {
            return $this->problem($window, $this->typedMonth($request, $today), $request);
        }
        try {
            $this->tie->tax($window->from, $window->to);
            $loaded = $this->sheet->sheet($window->from, $window->to);
        } catch (DatabaseRefusal $error) {
            return $this->problem(
                ReportWindow::failed($error->getMessage(), $window->query),
                substr($window->from, 0, 7),
                $request,
            );
        }

        return $this->ready($window, $request, $loaded);
    }

    /**
     * @param list<array{section: string, label: string, sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}> $loaded
     */
    private function ready(ReportWindow $window, Request $request, array $loaded): ReportPage
    {
        $liability = $this->filing->liability($loaded);
        $grid = $this->rows->tax($this->filing->detail($loaded));

        return new ReportPage(
            'Feature/Report/sales-tax',
            [
                'title' => 'Monthly sales tax',
                'label' => $window->label,
                'month' => substr($window->from, 0, 7),
                'print' => $request->query('print') === '1',
                'error' => null,
                'filing' => $this->filing->filing($loaded),
                'rates' => $this->filing->rates($loaded),
                'liability' => $liability,
                'headers' => $this->headers($grid),
                'lines' => $this->table->cells($grid->columns, $grid->rows),
                'download' => 'sales-tax.csv?month=' . substr($window->from, 0, 7),
            ],
            $this->table->document($grid->columns, $grid->rows),
            'sales-tax.csv',
        );
    }

    private function problem(ReportWindow $window, string $month, Request $request): ReportPage
    {
        return new ReportPage(
            'Feature/Report/sales-tax',
            [
                'title' => 'Monthly sales tax',
                'label' => $window->label,
                'month' => $month,
                'print' => $request->query('print') === '1',
                'error' => $window->error,
                'filing' => null,
                'rates' => [],
                'liability' => null,
                'headers' => [],
                'lines' => [],
                'download' => 'sales-tax.csv' . ($window->query === '' ? '' : '?' . $window->query),
            ],
            null,
            'sales-tax.csv',
        );
    }

    private function typedMonth(Request $request, string $today): string
    {
        $typed = $request->query('month');
        if ($typed === '') {
            return substr($today, 0, 7);
        }

        return $typed;
    }

    /**
     * @return list<string>
     */
    private function headers(ReportGrid $grid): array
    {
        $headers = [];
        foreach ($grid->columns as $column) {
            $headers[] = $column->label;
        }

        return $headers;
    }
}
