<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Home;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class HomeController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, false);
        $this->app->show('Shared/Web/home', [
            'title' => $tenant->displayName,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
        ]);
    }
}
