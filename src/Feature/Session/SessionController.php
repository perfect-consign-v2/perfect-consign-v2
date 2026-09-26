<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Session;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class SessionController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/login', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, false);
        $this->app->show('Shared/Web/login', [
            'title' => 'Sign in',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/login', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, false);
        $username = $this->app->request()->field('username');
        $password = $this->app->request()->raw('password');
        if (!$this->app->signIn($slug, $username, $password)) {
            $this->app->show('Shared/Web/login', [
                'title' => 'Sign in',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => 'That username or password is wrong.',
            ]);
            return;
        }
        $this->app->redirect('/' . $slug . '/book');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/logout', ['POST'])]
    public function logout(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->signOut();
        $this->app->redirect('/' . $slug);
    }
}
