<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class PosHeldController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PosPage $page,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/suspended', ['GET'])]
    public function suspended(string $slug): void
    {
        $this->app->open($slug, true);
        $this->page->held($slug, 'parked');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/layaways', ['GET'])]
    public function layaways(string $slug): void
    {
        $this->app->open($slug, true);
        $this->page->held($slug, 'layaway');
    }
}
