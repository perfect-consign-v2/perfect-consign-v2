<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaSwitch;
use PerfectConsign\Feature\Signup\PlatformSettings;

final class AdminAuthenticatorController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly PlatformSettings $settings,
        private readonly AdminMfaStore $store,
        private readonly AdminMfaSwitch $switch,
        private readonly AdminMfaPending $pending,
    ) {
    }

    #[AdminRoute('/authenticator', ['GET'])]
    public function show(): void
    {
        $admin = $this->app->requireAdmin();
        $this->paint($admin['id'], null);
    }

    #[AdminRoute('/authenticator', ['POST'])]
    public function save(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $enabled = $this->app->request()->field('admin_mfa_enabled') === '1';
        if ($enabled === $this->settings->adminMfaEnabled()) {
            $this->app->redirect(
                $this->app->url('/authenticator'),
                $enabled ? 'Authenticator is already on.' : 'Authenticator is already off.',
            );
        }
        try {
            if ($enabled) {
                $this->switch->turnOn($admin['id'], $admin['email'], $this->app->request()->raw('current'));
            } else {
                $this->switch->turnOff(
                    $admin['id'],
                    $admin['email'],
                    $this->app->request()->raw('current'),
                    $this->app->request()->field('code'),
                    time(),
                );
            }
        } catch (SignInLocked) {
            $this->paint($admin['id'], 'Too many sign-in attempts. Try again in a few minutes.');

            return;
        } catch (\InvalidArgumentException $error) {
            $this->paint($admin['id'], $error->getMessage());

            return;
        }
        if ($enabled && !$this->store->hasSecret($admin['id'])) {
            $this->app->session()->clear();
            $this->pending->beginEnrollment($admin['id'], $admin['email']);
            $this->app->redirect($this->app->url('/mfa/enroll'));
        }
        $this->app->redirect(
            $this->app->url('/authenticator'),
            $enabled
                ? 'Authenticator is on. The next sign-in asks for a code.'
                : 'Authenticator is off. Sign-in is email and password.',
        );
    }

    private function paint(string $adminId, ?string $error): void
    {
        $enabled = $this->settings->adminMfaEnabled();
        $this->app->show('Feature/Admin/authenticator', [
            'title' => 'Authenticator',
            'enabled' => $enabled,
            'enrolled' => $this->store->hasSecret($adminId),
            'error' => $error,
        ]);
    }
}
