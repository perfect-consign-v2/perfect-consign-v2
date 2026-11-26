<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class PasswordController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly OwnPassword $passwords,
        private readonly LoginAccounts $accounts,
        private readonly Actor $actor,
        private readonly JournalAudit $audit,
        private readonly SignInDevices $devices,
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

    #[Route('/([a-z][a-z0-9-]{0,62})/password/devices/remove', ['POST'])]
    public function removeDevice(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            $this->devices->forget($tenant, $this->actor->username(), $this->app->request()->field('device_id'));
        } catch (\InvalidArgumentException $error) {
            $this->render($tenant, $error->getMessage());

            return;
        }
        $this->app->redirect(
            '/' . $tenant->slug . '/password',
            'That browser was removed. It needs the password the next time.',
        );
    }

    private function changed(TenantRecord $tenant, string $current, string $next, string $confirm): void
    {
        $account = $this->actor->account();
        $this->audit->within(function () use ($tenant, $account, $current, $next, $confirm): void {
            if ($this->actor->role() === 'owner') {
                $this->passwords->changeOwner($tenant, $account, $current, $next, $confirm);
            } else {
                $this->passwords->changeStaff($tenant, $account, $current, $next, $confirm);
            }
            $this->audit->record('password.change', 'account', $this->actor->account());
        });
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
        return $this->actor->account();
    }

    private function render(TenantRecord $tenant, ?string $error): void
    {
        $this->app->show('Feature/Password/password', [
            'title' => 'Change password',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'email' => $this->accounts->shown($tenant, $this->actor->role(), $this->actor->account()),
            'devices' => $this->devices->listed($tenant, $this->actor->username()),
            'error' => $error,
        ]);
    }
}
