<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class PasswordController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly OwnPassword $passwords,
        private readonly LoginAccounts $accounts,
        private readonly LoginSession $sessions,
        private readonly Actor $actor,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/password', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($this->app->open($slug, true), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/password', ['POST'])]
    public function change(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $version = $this->changed(
                $tenant,
                $request->raw('current_password'),
                $request->raw('password'),
                $request->raw('confirm'),
            );
            $this->sessions->stamp($version);
        } catch (\InvalidArgumentException $error) {
            $this->render($tenant, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/password', 'Password updated.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/password/email', ['POST'])]
    public function email(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->saveEmail($tenant, $request->raw('current_password'), $request->field('email'));
        } catch (\InvalidArgumentException $error) {
            $this->render($tenant, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/password', 'Recovery email saved.');
    }

    private function changed(TenantRecord $tenant, string $current, string $next, string $confirm): string
    {
        $account = $this->account();
        if ($this->actor->role() === 'owner') {
            return $this->passwords->changeOwner($tenant, $account, $current, $next, $confirm);
        }

        return $this->passwords->changeStaff($tenant, $account, $current, $next, $confirm);
    }

    private function saveEmail(TenantRecord $tenant, string $current, string $email): void
    {
        $account = $this->account();
        if ($this->actor->role() === 'owner') {
            $this->passwords->emailOwner($tenant, $account, $current, $email);

            return;
        }
        $this->passwords->emailStaff($tenant, $account, $current, $email);
    }

    private function account(): string
    {
        $id = $this->actor->account();
        if ($id === '') {
            throw new \InvalidArgumentException('Sign in again.');
        }

        return $id;
    }

    private function render(TenantRecord $tenant, ?string $error): void
    {
        $this->app->show('Feature/Password/password', [
            'title' => 'Password',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'email' => $this->accounts->shown($tenant, $this->actor->role(), $this->actor->account()),
            'error' => $error,
        ]);
    }
}
