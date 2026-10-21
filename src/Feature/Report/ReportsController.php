<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\Permission;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ReportsController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BooksReport $report,
        private readonly Actor $actor,
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
            'rows' => $this->report->rows(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports.csv', ['GET'])]
    public function csv(string $slug): void
    {
        $this->open($slug);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="accounts.csv"');
        echo "code,account_type,balance_minor\n";
        foreach ($this->report->rows() as $row) {
            echo $row['code'] . ',' . $row['account_type'] . ',' . $row['balance_minor'] . "\n";
        }
        throw new ResponseSent('csv');
    }

    private function open(string $slug): TenantRecord
    {
        $tenant = $this->app->open($slug, true);
        $role = $this->actor->role();
        if (!Permission::allows($role, 'settings.edit') && $role !== 'books') {
            $this->app->notFound();
        }

        return $tenant;
    }
}
