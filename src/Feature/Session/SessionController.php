<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Session;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
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

    #[Route('/([a-z][a-z0-9-]{0,62})/login', ['GET', 'POST'])]
    public function form(string $slug): void
    {
        $this->app->open($slug, false);
        $this->app->redirect('/');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/logout', ['POST'])]
    public function logout(string $slug): void
    {
        $this->app->checkCsrf();
        if ($this->actor->role() !== 'owner') {
            $this->app->signOut();
            $this->app->redirect('/', 'Signed out.');
        }
        $this->confirm($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/logout/confirm', ['POST'])]
    public function confirmLogout(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->app->signOut();
        $this->app->redirect('/', 'Signed out.');
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
