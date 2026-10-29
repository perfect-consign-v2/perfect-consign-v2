<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class PasswordController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly OwnPassword $passwords,
        private readonly LoginAccounts $accounts,
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
            $email = $this->accounts->shown($tenant, $this->actor->role(), $this->account());
            $this->changed(
                $tenant,
                $request->raw('current_password'),
                $request->raw('password'),
                $request->raw('confirm'),
            );
        } catch (\InvalidArgumentException $error) {
            $this->render($tenant, $error->getMessage());

            return;
        }
        $this->app->signOut();
        $this->app->redirect($this->signInPath($email), 'Password updated. Sign in with your new password.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/password/email', ['POST'])]
    public function email(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $request = $this->app->request();
        $email = $request->field('email');
        try {
            $this->saveEmail($tenant, $request->raw('current_password'), $email);
        } catch (\InvalidArgumentException $error) {
            $this->render($tenant, $error->getMessage());

            return;
        }
        $this->app->signOut();
        $this->app->redirect($this->signInPath($email), 'Sign-in email saved. Sign in with your new email.');
    }

    private function changed(TenantRecord $tenant, string $current, string $next, string $confirm): void
    {
        $account = $this->account();
        if ($this->actor->role() === 'owner') {
            $this->passwords->changeOwner($tenant, $account, $current, $next, $confirm);

            return;
        }

        $this->passwords->changeStaff($tenant, $account, $current, $next, $confirm);
    }

    private function signInPath(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '/';
        }

        return '/?email=' . rawurlencode($email);
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
            'title' => 'Change password',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'email' => $this->accounts->shown($tenant, $this->actor->role(), $this->actor->account()),
            'error' => $error,
        ]);
    }
}
