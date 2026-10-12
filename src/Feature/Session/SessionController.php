<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Session;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Backup\BackupHeadline;
use PerfectConsign\Feature\Backup\BackupStore;

final class SessionController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BackupStore $backups,
        private readonly Actor $actor,
    ) {
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
        try {
            $signedIn = $this->app->signIn($slug, $username, $password);
        } catch (SignInLocked) {
            $this->app->show('Shared/Web/login', [
                'title' => 'Sign in',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => 'Too many sign-in attempts. Try again in a few minutes.',
            ]);

            return;
        }
        if (!$signedIn) {
            $this->app->show('Shared/Web/login', [
                'title' => 'Sign in',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => 'That username or password is wrong.',
            ]);

            return;
        }
        $this->app->redirect($this->actor->path($slug), 'Signed in.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/logout', ['POST'])]
    public function logout(string $slug): void
    {
        $this->app->checkCsrf();
        if ($this->actor->role() !== 'owner') {
            $this->app->signOut();
            $this->app->redirect('/' . $slug, 'Signed out.');
        }
        $this->confirm($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/logout/confirm', ['POST'])]
    public function confirmLogout(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->app->signOut();
        $this->app->redirect('/' . $slug, 'Signed out.');
    }

    private function confirm(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Session/sign-out', [
            'title' => 'Sign out',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'backupHeadline' => BackupHeadline::text(
                $this->backups->latest($tenant->id),
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            ),
            'error' => $error,
        ]);
    }
}
