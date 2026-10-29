<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Home;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;

final class HomeController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, false);
        if ($this->actor->slug() === $tenant->slug) {
            $this->app->redirect($this->actor->path($tenant->slug));
        }
        $this->app->redirect('/');
    }
}
