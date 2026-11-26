<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Signup\SignupAdmin;
use PerfectConsign\Feature\Signup\StoreSignup;
use RuntimeException;

final class AdminSignupController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly PlatformSettings $settings,
        private readonly SignupAdmin $signups,
        private readonly StoreSignup $signup,
        private readonly AdminAudit $audit,
    ) {
    }

    #[AdminRoute('/signup', ['GET'])]
    public function show(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/signup-settings', [
            'title' => 'Signups',
            'enabled' => $this->settings->storeSignupEnabled(),
            'signups' => $this->signups->listed(),
            'reaped' => $this->signups->reapedLately(),
            'pendingHours' => $this->signup->pendingHours(),
            'unconfirmed' => $this->signups->unconfirmedStores(),
            'error' => null,
        ]);
    }

    #[AdminRoute('/signup', ['POST'])]
    public function save(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $enabled = $this->app->request()->field('store_signup_enabled') === '1';
        $this->settings->setStoreSignupEnabled($enabled);
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'store.signup_toggle',
            'ok',
            null,
            null,
            ['enabled' => $enabled],
        );
        $this->app->redirect(
            $this->app->url('/signup'),
            $enabled ? 'Public store signup is on.' : 'Public store signup is off.'
        );
    }

    #[AdminRoute('/signup/resend', ['POST'])]
    public function resend(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $request = $this->app->request();
        try {
            $email = $this->signups->resend(
                $request->field('signup_id'),
                $request->origin,
                $admin,
                $this->signup->pendingHours(),
            );
        } catch (RuntimeException $error) {
            $this->app->redirect($this->app->url('/signup'), null, $error->getMessage());
        }
        $this->app->redirect($this->app->url('/signup'), 'A new link was emailed to ' . $email . '.');
    }

    #[AdminRoute('/signup/discard', ['POST'])]
    public function discard(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        try {
            $email = $this->signups->discard($this->app->request()->field('signup_id'), $admin);
        } catch (RuntimeException $error) {
            $this->app->redirect($this->app->url('/signup'), null, $error->getMessage());
        }
        $this->app->redirect($this->app->url('/signup'), 'The signup for ' . $email . ' was discarded.');
    }
}
