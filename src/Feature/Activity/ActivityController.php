<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Http\Csv;
use PerfectConsign\Shared\Http\ResponseSent;

final class ActivityController
{
    private const PAGE = 200;

    private const EXPORT = 5000;

    public function __construct(
        private readonly MallApp $app,
        private readonly ActivityLog $log,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/activity', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $filter = ActivityFilter::fromRequest($this->app->request());
        $this->page($tenant->displayName, $tenant->slug, $filter, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/activity\\.csv', ['GET'])]
    public function csv(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $filter = ActivityFilter::fromRequest($this->app->request());
        if ($filter->error !== null) {
            $this->page($tenant->displayName, $tenant->slug, $filter, null);

            return;
        }
        if ($this->log->count($filter) > self::EXPORT) {
            $this->page($tenant->displayName, $tenant->slug, $filter, 'Narrow the dates.');

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

    private function page(string $displayName, string $slug, ActivityFilter $filter, ?string $error): void
    {
        $rows = $filter->error === null ? $this->log->rows($filter, self::PAGE) : [];
        $this->app->show('Feature/Activity/activity', [
            'title' => 'Activity',
            'displayName' => $displayName,
            'slug' => $slug,
            'filter' => $filter,
            'rows' => $rows,
            'error' => $error ?? $filter->error,
            'export' => $filter->query(),
        ]);
    }
}
