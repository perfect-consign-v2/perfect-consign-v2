<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\MallApp;

final class RootController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/', ['GET'])]
    public function show(): void
    {
        $this->app->show('root', [
            'title' => 'Perfect Consign',
            'displayName' => '',
            'slug' => '',
        ]);
    }
}
