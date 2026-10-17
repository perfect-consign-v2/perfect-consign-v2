<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Sales;

use DateTimeImmutable;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Dashboard\DashboardStore;
use PerfectConsign\Feature\Mall\MallSettings;

final class SalesController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly DashboardStore $sales,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/sales', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $range = $this->app->request()->query('range');
        $day = $today;
        $label = 'Today';
        if ($range === 'yesterday') {
            $day = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            $label = 'Yesterday';
        } elseif ($range === 'all') {
            $day = '';
            $label = 'Recent sales';
        }
        $this->app->show('Feature/Sales/sales', [
            'title' => 'Sales',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'range' => $range === 'yesterday' || $range === 'all' ? $range : 'today',
            'label' => $label,
            'rows' => $this->sales->sales($day, 200),
        ]);
    }
}
