<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Vendor\VendorPortalAccess;
use PerfectConsign\Feature\Vendor\VendorPreview;

/** JSON pages for the staff and vendor item tables. */
final class ItemRowsController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly FrontDoor $door,
        private readonly ItemBoard $board,
        private readonly VendorPreview $preview,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/rows', ['GET'])]
    public function staff(string $slug): void
    {
        $this->app->open($slug, true);
        $this->board->send($this->app->request(), $slug, $this->door->token(), '', '', 'staff');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory/rows', ['GET'])]
    public function vendor(string $slug): void
    {
        $this->app->open($slug, true);
        if (!(new VendorPortalAccess($this->mall))->inventoryEnabled()) {
            $this->app->notFound();
        }
        $request = $this->app->request();
        $this->board->send(
            $request,
            $slug,
            $this->door->token(),
            $this->preview->id($slug),
            VendorPreview::token($request->query('as')),
            'vendor',
        );
    }
}
