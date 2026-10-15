<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Month;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\PayoutWindow;

final class MonthController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly PayoutWindow $window,
        private readonly MonthStore $months,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/month', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $span = $this->window->window($this->mall->today(), $this->mall->getCycleDay());
        $monthName = (new \DateTimeImmutable($span['from']))->format('F Y');
        $this->app->show('Feature/Month/month', [
            'title' => $monthName,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'monthName' => $monthName,
            'figures' => $this->months->figures($span['from'], $span['to']),
        ]);
    }
}
