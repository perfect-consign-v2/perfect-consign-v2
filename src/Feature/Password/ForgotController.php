<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class ForgotController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PasswordRecovery $recovery,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/forgot', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, false);
        $this->app->show('Feature/Password/forgot', [
            'title' => 'Forgot password',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/forgot', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, false);
        $this->recovery->request($tenant, $this->app->request()->field('username'), $this->app->request()->origin);
        $this->app->redirect('/' . $slug . '/forgot', ResetNotice::SENT);
    }
}
