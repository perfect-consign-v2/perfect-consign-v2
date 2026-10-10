<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Mall\MallSettings;

final class VendorHomeController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly VendorAccount $account,
        private readonly MallSettings $mall,
        private readonly LabelCatalog $catalog,
        private readonly VendorStickers $stickers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor', ['GET'])]
    public function home(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $vendorId = $this->actor->vendor();
        if ($vendorId === '') {
            $this->app->notFound();
        }
        $numbers = $this->account->numbers($vendorId);
        $this->app->show('Feature/Vendor/home', [
            'title' => 'Your account',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'payable' => $numbers['payable'],
            'rent' => $numbers['rent'],
            'pieces' => $this->account->pieces($vendorId),
            'labelStock' => $this->mall->getLabelStock(),
            'stocks' => $this->catalog->choices(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/labels', ['GET'])]
    public function labels(string $slug): void
    {
        $this->home($slug);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/labels', ['POST'])]
    public function printLabels(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->stickers->printOwn($slug, $this->actor->vendor());
    }
}
