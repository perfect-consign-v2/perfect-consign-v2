<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Http\Csv;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ActivityController
{
    private const PAGE = 50;

    private const EXPORT = 5000;

    public function __construct(
        private readonly MallApp $app,
        private readonly ActivityLog $log,
        private readonly ActivityPeople $people,
        private readonly ActivityHref $hrefs,
        private readonly Actor $actor,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/activity', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->render($tenant, ActivityFilter::fromRequest($this->app->request()), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/activity\\.csv', ['GET'])]
    public function csv(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $filter = ActivityFilter::fromRequest($this->app->request());
        if ($filter->error !== null) {
            $this->render($tenant, $filter, null);

            return;
        }
        if ($filter->before !== null || $filter->after !== null) {
            $this->render($tenant, $filter, 'Download uses the date, person, and action.');

            return;
        }
        if ($this->log->count($filter) > self::EXPORT) {
            $this->render($tenant, $filter, 'Narrow the dates.');

            return;
        }
        $rows = [];
        foreach ($this->log->rows($filter, self::EXPORT) as $row) {
            $rows[] = [
                'when' => $row['at'],
                'username' => $row['recorded'] ? $row['username'] : 'not recorded',
                'name' => $row['name'],
                'role' => $row['role'],
                'action' => $row['action'],
                'entity' => $row['entity'],
                'authority' => $row['authority'],
                'entered_by' => $row['entered_by'],
                'address' => $row['address'],
            ];
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="activity.csv"');
        header('Cache-Control: no-store');
        echo Csv::document(
            ['when', 'username', 'name', 'role', 'action', 'entity', 'authority', 'entered_by', 'address'],
            $rows,
        );
        throw new ResponseSent('activity');
    }

    private function render(TenantRecord $tenant, ActivityFilter $filter, ?string $error): void
    {
        $failure = $error ?? $filter->error;
        $rows = [];
        $older = null;
        $newer = null;
        $total = 0;
        if ($failure === null) {
            $window = $this->log->page($filter, self::PAGE);
            if ($window === null) {
                $failure = 'That page is not in this list.';
            } else {
                $rows = $this->hrefs->apply(
                    $tenant->slug,
                    $this->actor->role(),
                    $this->mall->isConsignmentOnly() ? 'consignors' : 'vendors',
                    $window['rows'],
                );
                $older = $window['older'];
                $newer = $window['newer'];
                $total = $this->log->count($filter);
            }
        }
        $seller = $this->mall->isConsignmentOnly() ? 'consignor' : 'vendor';
        $this->app->show('Feature/Activity/activity', [
            'title' => 'Activity',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'filter' => $filter,
            'rows' => $rows,
            'error' => $failure,
            'export' => $filter->query(),
            'people' => $this->people->list($tenant),
            'actions' => ActivityPhrase::choices($seller),
            'older' => $older,
            'newer' => $newer,
            'total' => $total,
            'seller' => $seller,
        ]);
    }
}
