<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class PosNextController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/new', ['POST'])]
    public function start(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->sales->clear($slug);
        $this->app->redirect('/' . $slug . '/pos');
    }
}
