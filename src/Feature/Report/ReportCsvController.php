<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\Permission;
use PerfectConsign\Shared\Http\Csv;
use PerfectConsign\Shared\Http\ResponseSent;

final class ReportCsvController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly SalesTaxSheet $tax,
        private readonly LedgerReport $ledger,
        private readonly ReportMonth $months,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales-tax.csv', ['GET'])]
    public function salesTaxCsv(string $slug): void
    {
        $this->open($slug);
        $bounds = $this->months->bounds($this->month());
        $this->csv('sales-tax.csv', ['section', 'label', 'sale_count', 'items_sold', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor'], $this->tax->sheet($bounds['from'], $bounds['to']));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales.csv', ['GET'])]
    public function salesCsv(string $slug): void
    {
        $this->open($slug);
        $this->csv('sales.csv', ['label', 'sales_minor', 'tax_minor', 'sale_count'], $this->salesRows());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent.csv', ['GET'])]
    public function rentCsv(string $slug): void
    {
        $this->open($slug);
        $this->csv('rent.csv', ['booth', 'vendor', 'open_minor'], $this->ledger->rent());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/payouts.csv', ['GET'])]
    public function payoutsCsv(string $slug): void
    {
        $this->open($slug);
        $this->csv('payouts.csv', ['paid_on', 'vendor', 'amount_minor', 'kind'], $this->payoutRows());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/stored-value.csv', ['GET'])]
    public function storedValueCsv(string $slug): void
    {
        $this->open($slug);
        $this->csv('stored-value.csv', ['kind', 'balance_minor'], $this->ledger->storedValue());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/expiring.csv', ['GET'])]
    public function expiringCsv(string $slug): void
    {
        $this->open($slug);
        $this->csv('expiring.csv', ['code', 'description', 'vendor', 'expires_on', 'quantity', 'price_minor'], $this->ledger->expiring());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/donated.csv', ['GET'])]
    public function donatedCsv(string $slug): void
    {
        $this->open($slug);
        $bounds = $this->span();
        $this->csv('donated.csv', ['posted_on', 'code', 'description', 'quantity'], $this->ledger->donated($bounds['from'], $bounds['to']));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sell-through.csv', ['GET'])]
    public function sellThroughCsv(string $slug): void
    {
        $this->open($slug);
        $bounds = $this->span();
        $this->csv(
            'sell-through.csv',
            ['category', 'sold_qty', 'on_hand_qty', 'sales_minor'],
            $this->ledger->sellThrough($bounds['from'], $bounds['to']),
        );
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function salesRows(): array
    {
        $bounds = $this->span();
        $group = $this->app->request()->query('group');
        if (!in_array($group, ['day', 'tender', 'vendor', 'cashier', 'hour', 'category'], true)) {
            $group = 'day';
        }

        return $this->ledger->sales($bounds['from'], $bounds['to'], $group);
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function payoutRows(): array
    {
        $bounds = $this->span();

        return $this->ledger->payouts($bounds['from'], $bounds['to']);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function span(): array
    {
        $from = $this->app->request()->query('from');
        $to = $this->app->request()->query('to');
        $fromOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1;
        $toOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1;
        if (!$fromOk || !$toOk) {
            return $this->months->bounds(date('Y-m'));
        }
        if ($to < $from) {
            $to = $from;
        }

        return ['from' => $from, 'to' => $to];
    }

    private function month(): string
    {
        $month = $this->app->request()->query('month');
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return date('Y-m');
        }

        return $month;
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, int|string>> $rows
     */
    private function csv(string $name, array $columns, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo Csv::document($columns, $rows);
        throw new ResponseSent('csv');
    }

    private function open(string $slug): void
    {
        $this->app->open($slug, true);
        $role = $this->actor->role();
        if (!Permission::allows($role, 'report.read') && !Permission::allows($role, 'settings.edit')) {
            $this->app->notFound();
        }
    }
}
