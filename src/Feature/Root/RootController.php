<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Root;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class RootController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/', ['GET'])]
    public function show(): void
    {
        $this->app->show('Shared/Web/root', [
            'title' => 'Perfect Consign',
            'displayName' => '',
            'slug' => '',
        ]);
    }
}
