<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class ForgotController
{
    public function __construct(
        private readonly MallApp $app,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/forgot', ['GET', 'POST'])]
    public function form(string $slug): void
    {
        $this->app->open($slug, false);
        $this->app->redirect('/forgot');
    }
}
