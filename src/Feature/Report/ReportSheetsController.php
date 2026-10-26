<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\Permission;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ReportSheetsController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly LedgerReport $ledger,
        private readonly ReportMonth $months,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent', ['GET'])]
    public function rent(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Rent roll', 'rent', $this->ledger->rent());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/stored-value', ['GET'])]
    public function storedValue(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Stored value', 'stored', $this->ledger->storedValue());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/expiring', ['GET'])]
    public function expiring(string $slug): void
    {
        $this->open($slug);
        $this->named($slug, 'Expiring items', 'expiring', $this->ledger->expiring());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/donated', ['GET'])]
    public function donated(string $slug): void
    {
        $this->open($slug);
        $bounds = $this->span();
        $this->named($slug, 'Donated items', 'donated', $this->ledger->donated($bounds['from'], $bounds['to']));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sell-through', ['GET'])]
    public function sellThrough(string $slug): void
    {
        $this->open($slug);
        $bounds = $this->span();
        $this->named($slug, 'Category sell-through', 'sell-through', $this->ledger->sellThrough($bounds['from'], $bounds['to']));
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
