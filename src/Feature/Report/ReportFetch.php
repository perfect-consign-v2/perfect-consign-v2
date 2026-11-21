<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Request;

final class ReportFetch
{
    private readonly ReportSpan $span;

    private readonly ReportRows $rows;

    private readonly ReportTable $table;

    public function __construct(
        private readonly LedgerReport $ledger,
        private readonly ReportTie $tie,
    ) {
        $this->span = new ReportSpan();
        $this->rows = new ReportRows();
        $this->table = new ReportTable();
    }

    public function sales(Request $request, string $today): ReportPage
    {
        $window = $this->span->sales($request, $today);
        $group = $window->group !== '' ? $window->group : ($request->query('group') === 'hour' ? 'hour' : 'day');
        $mode = $group === 'hour' ? 'hour' : 'range';
        $sentence = $this->salesSentence($group, $window->label);
        $empty = $group === 'hour' ? 'No sales on this day.' : 'No sales in this range.';
        if ($window->isFailed()) {
            return $this->problem('Sales', $sentence, 'sales.csv', $mode, $window);
        }
        try {
            $grid = $this->rows->sales($window->group, $this->ledger->sales($window->from, $window->to, $window->group));
        } catch (DatabaseRefusal $error) {
            return $this->problem('Sales', $sentence, 'sales.csv', $mode, ReportWindow::failed($error->getMessage(), $window->query));
        }

        return $this->ready($window, 'Sales', $sentence, $empty, $mode, 'sales.csv', $grid);
    }

    public function payouts(Request $request, string $today): ReportPage
    {
        return $this->ranged(
            $request,
            $today,
            'Payments',
            'Checks, cash, the safe, and rent settled against what the vendor owed.',
            'No payments in this range.',
            'payouts.csv',
            fn (ReportWindow $window): ReportGrid => $this->rows->payouts($this->ledger->payouts($window->from, $window->to)),
        );
    }

    public function donated(Request $request, string $today): ReportPage
    {
        return $this->ranged(
            $request,
            $today,
            'Donated items',
            'Pieces given to the store.',
            'No donated items in this range.',
            'donated.csv',
            fn (ReportWindow $window): ReportGrid => $this->rows->donated($this->ledger->donated($window->from, $window->to)),
        );
    }

    public function sellThrough(Request $request, string $today): ReportPage
    {
        return $this->ranged(
            $request,
            $today,
            'Category sell-through',
            'Sold in this range. On hand is today.',
            'No categories in this range.',
            'sell-through.csv',
            fn (ReportWindow $window): ReportGrid => $this->rows->sellThrough($this->ledger->sellThrough($window->from, $window->to)),
        );
    }

    public function rent(Request $request, string $today): ReportPage
    {
        return $this->pinned(
            $request,
            $today,
            'Rent',
            'Open booth rent. A zero balance stays off this list.',
            'Nobody owes rent.',
            'rent.csv',
            function (): ReportGrid {
                $this->tie->rent();

                return $this->rows->rent($this->ledger->rent());
            },
        );
    }

    public function stored(Request $request, string $today): ReportPage
    {
        return $this->pinned(
            $request,
            $today,
            'Gift cards and store credit',
            'What the mall still owes on gift cards, gift certificates, and store credit.',
            'No stored value is on the books.',
            'stored-value.csv',
            function (): ReportGrid {
                $this->tie->stored();

                return $this->rows->stored($this->ledger->storedValue());
            },
        );
    }

    public function expiring(Request $request, string $today): ReportPage
    {
        return $this->pinned(
            $request,
            $today,
            'Expiring items',
            'Consigned pieces that expire in the next 14 days.',
            'Nothing expires in the next 14 days.',
            'expiring.csv',
            fn (): ReportGrid => $this->rows->expiring($this->ledger->expiring()),
        );
    }

    /**
     * @param callable(ReportWindow): ReportGrid $load
     */
    private function ranged(
        Request $request,
        string $today,
        string $title,
        string $sentence,
        string $empty,
        string $filename,
        callable $load,
    ): ReportPage {
        $window = $this->span->ranged($request, $today);
        if ($window->isFailed()) {
            return $this->problem($title, $sentence, $filename, 'range', $window);
        }
        try {
            $grid = $load($window);
        } catch (DatabaseRefusal $error) {
            return $this->problem($title, $sentence, $filename, 'range', ReportWindow::failed($error->getMessage(), $window->query));
        }

        return $this->ready($window, $title, $sentence, $empty, 'range', $filename, $grid);
    }

    /**
     * @param callable(): ReportGrid $load
     */
    private function pinned(
        Request $request,
        string $today,
        string $title,
        string $sentence,
        string $empty,
        string $filename,
        callable $load,
    ): ReportPage {
        $window = $this->span->pinned($request, $today);
        if ($window->isFailed()) {
            return $this->problem($title, $sentence, $filename, 'none', $window);
        }
        try {
            $grid = $load();
        } catch (DatabaseRefusal $error) {
            return $this->problem($title, $sentence, $filename, 'none', ReportWindow::failed($error->getMessage(), $window->query));
        }

        return $this->ready($window, $title, $sentence, $empty, 'none', $filename, $grid);
    }

    private function ready(
        ReportWindow $window,
        string $title,
        string $sentence,
        string $empty,
        string $mode,
        string $filename,
        ReportGrid $grid,
    ): ReportPage {
        $rows = $grid->rows;

        return new ReportPage(
            'Feature/Report/sheet',
            $this->data(
                $window,
                $title,
                $sentence,
                $rows === [] ? $empty : null,
                $mode,
                $filename,
                $this->headers($grid),
                $this->table->cells($grid->columns, $rows),
                $this->table->total($grid->columns, $rows),
            ),
            $this->table->document($grid->columns, $rows),
            $filename,
        );
    }

    private function problem(
        string $title,
        string $sentence,
        string $filename,
        string $mode,
        ReportWindow $window,
    ): ReportPage {
        return new ReportPage(
            'Feature/Report/sheet',
            $this->data($window, $title, $sentence, null, $mode, $filename, [], [], null),
            null,
            $filename,
        );
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $lines
     * @param list<string>|null $total
     * @return array<string, mixed>
     */
    private function data(
        ReportWindow $window,
        string $title,
        string $sentence,
        ?string $empty,
        string $mode,
        string $filename,
        array $headers,
        array $lines,
        ?array $total,
    ): array {
        return [
            'title' => $title,
            'sentence' => $sentence,
            'label' => $window->label,
            'error' => $window->error,
            'empty' => $window->error === null ? $empty : null,
            'headers' => $headers,
            'lines' => $lines,
            'total' => $total,
            'download' => $filename . ($window->query === '' ? '' : '?' . $window->query),
            'mode' => $mode,
            'group' => $window->group,
            'from' => $window->from,
            'to' => $window->to,
        ];
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

    private function salesSentence(string $group, string $label): string
    {
        if ($group === 'tender') {
            return 'What the drawer, card, and certificates took, including tax.';
        }
        if ($group === 'hour') {
            return 'Hourly sales on ' . $label . '. Price after discounts, before tax.';
        }

        return 'Price after discounts, before tax. Returns and voids count on the day they post.';
    }
}
