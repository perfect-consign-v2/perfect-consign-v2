<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\Permission;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ReportsController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly SalesTaxSheet $tax,
        private readonly SalesTaxFiling $filing,
        private readonly LedgerReport $ledger,
        private readonly ReportMonth $months,
        private readonly SavedReport $saved,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->open($slug);
        $this->app->show('Feature/Report/reports', [
            'title' => 'Reports',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'saved' => $this->saved->list(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales-tax', ['GET'])]
    public function salesTax(string $slug): void
    {
        $tenant = $this->open($slug);
        $month = $this->month();
        $bounds = $this->months->bounds($month);
        $rows = $this->tax->sheet($bounds['from'], $bounds['to']);
        $this->app->show('Feature/Report/sales-tax', [
            'title' => 'Monthly sales tax',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'month' => $month,
            'rows' => $rows,
            'filing' => $this->filing->filing($rows),
            'print' => $this->app->request()->query('print') === '1',
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales', ['GET'])]
    public function sales(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Sales', 'sales', $this->salesRows());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent', ['GET'])]
    public function rent(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Rent roll', 'rent', $this->ledger->rent());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/payouts', ['GET'])]
    public function payouts(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Payout register', 'payouts', $this->payoutRows());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/stored-value', ['GET'])]
    public function storedValue(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Stored value', 'stored', $this->ledger->storedValue());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/build', ['GET'])]
    public function build(string $slug): void
    {
        $tenant = $this->open($slug);
        $this->app->show('Feature/Report/build', [
            'title' => 'Report builder',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => null,
            'saved' => $this->saved->list(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/build', ['POST'])]
    public function store(string $slug): void
    {
        $this->app->checkCsrf();
        $this->open($slug);
        $request = $this->app->request();
        try {
            $this->saved->save($request->field('name'), $request->field('source'), $request->field('grouped'));
        } catch (\InvalidArgumentException $error) {
            $tenant = $this->app->open($slug, true);
            $this->app->show('Feature/Report/build', [
                'title' => 'Report builder',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => $error->getMessage(),
                'saved' => $this->saved->list(),
            ]);

            return;
        }
        $this->app->redirect('/' . $slug . '/reports/build', 'Report saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/saved/([0-9a-fA-F-]{36})', ['GET'])]
    public function savedReport(string $slug, string $id): void
    {
        $this->open($slug);
        $report = $this->saved->find($id);
        if ($report === null) {
            $this->app->notFound();
        }
        $rows = $this->savedRows($report['source'], $report['grouped']);
        $this->named($slug, $report['name'], 'saved', $rows);
    }

    /**
     * @param list<array<string, int|string>> $rows
     */
    private function named(string $slug, string $title, string $kind, array $rows): void
    {
        $tenant = $this->open($slug);
        $this->app->show('Feature/Report/table', [
            'title' => $title,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'kind' => $kind,
            'rows' => $rows,
        ]);
    }

    /**
     * @return list<array{label: string, sales_minor: int, tax_minor: int, sale_count: int}>
     */
    private function salesRows(): array
    {
        $bounds = $this->span();
        $grouped = $this->app->request()->query('group');
        if (!in_array($grouped, ['day', 'tender', 'vendor', 'cashier'], true)) {
            $grouped = 'day';
        }

        return $this->ledger->sales($bounds['from'], $bounds['to'], $grouped);
    }

    /**
     * @return list<array{paid_on: string, vendor: string, amount_minor: int, kind: string}>
     */
    private function payoutRows(): array
    {
        $bounds = $this->span();

        return $this->ledger->payouts($bounds['from'], $bounds['to']);
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function savedRows(string $source, string $grouped): array
    {
        $bounds = $this->span();
        if ($source === 'sales') {
            $group = $grouped === 'none' ? 'day' : $grouped;

            return $this->ledger->sales($bounds['from'], $bounds['to'], $group);
        }
        if ($source === 'rent') {
            return $this->ledger->rent();
        }
        if ($source === 'payouts') {
            return $this->ledger->payouts($bounds['from'], $bounds['to']);
        }

        return $this->ledger->storedValue();
    }

    /**
     * @return array{from: string, to: string}
     */
    private function span(): array
    {
        $from = $this->app->request()->query('from');
        $to = $this->app->request()->query('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $to < $from) {
            return $this->months->bounds(date('Y-m'));
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

    private function open(string $slug): TenantRecord
    {
        $tenant = $this->app->open($slug, true);
        $role = $this->actor->role();
        if (!Permission::allows($role, 'report.read') && !Permission::allows($role, 'settings.edit')) {
            $this->app->notFound();
        }

        return $tenant;
    }
}
