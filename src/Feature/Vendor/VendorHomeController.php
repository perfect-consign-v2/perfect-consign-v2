<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\PayoutWindow;

final class VendorHomeController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly VendorAccount $account,
        private readonly VendorCheck $checks,
        private readonly VendorSales $sales,
        private readonly PayoutWindow $window,
        private readonly MallSettings $mall,
        private readonly LabelCatalog $catalog,
        private readonly VendorStickers $stickers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor', ['GET'])]
    public function home(string $slug): void
    {
        $vendorId = $this->vendor($slug);
        $tenant = $this->app->open($slug, true);
        $page = $this->checks->home($vendorId, $this->mall->today());
        $this->app->show('Feature/Vendor/home', [
            'title' => 'Your check',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'page' => $page,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/sales', ['GET'])]
    public function sales(string $slug): void
    {
        $vendorId = $this->vendor($slug);
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $request = $this->app->request();
        $span = $this->sales->span(
            $today,
            $request->query('range'),
            $request->query('from'),
            $request->query('to'),
            $this->checks->lastPaidOn($vendorId),
            $this->window->window($today)
        );
        $posted = $this->sales->lines($vendorId, $span['from'], $span['to']);
        $this->app->show('Feature/Vendor/sales', [
            'title' => 'Your sales',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'span' => $span,
            'rows' => $posted['rows'],
            'total' => $posted['total'],
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory', ['GET'])]
    public function items(string $slug): void
    {
        $this->itemPage($slug);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/labels', ['GET'])]
    public function labels(string $slug): void
    {
        $this->app->open($slug, true);
        $this->app->redirect('/' . $slug . '/vendor/inventory');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/labels', ['POST'])]
    public function printLabels(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->stickers->printOwn($slug, $this->actor->vendor());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/checks/([0-9a-fA-F-]{36})', ['GET'])]
    public function check(string $slug, string $runId): void
    {
        $vendorId = $this->vendor($slug);
        $tenant = $this->app->open($slug, true);
        $cleared = $this->checks->cleared($vendorId, $runId);
        if ($cleared === null) {
            $this->app->notFound();
        }
        $this->app->show('Feature/Vendor/past-check', [
            'title' => 'Your check',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'check' => $cleared,
        ]);
    }

    private function itemPage(string $slug): void
    {
        $vendorId = $this->vendor($slug);
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Vendor/items', [
            'title' => 'Your items',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'items' => $this->account->items($vendorId),
            'labelStock' => $this->mall->getLabelStock(),
            'stocks' => $this->catalog->choices(),
        ]);
    }

    private function vendor(string $slug): string
    {
        $this->app->open($slug, true);
        $vendorId = $this->actor->vendor();
        if ($vendorId === '') {
            $this->app->notFound();
        }

        return $vendorId;
    }
}
