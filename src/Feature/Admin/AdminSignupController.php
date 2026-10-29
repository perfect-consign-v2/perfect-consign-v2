<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;
use PerfectConsign\Feature\Signup\PlatformSettings;

final class AdminSignupController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly PlatformSettings $settings,
        private readonly AdminAudit $audit,
    ) {
    }

    #[Route('/admin/signup', ['GET'])]
    public function show(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/signup-settings', [
            'title' => 'Store signup',
            'enabled' => $this->settings->storeSignupEnabled(),
            'requireVerification' => $this->settings->requireEmailVerification(),
            'error' => null,
        ]);
    }

    #[Route('/admin/signup', ['POST'])]
    public function save(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $enabled = $this->app->request()->field('store_signup_enabled') === '1';
        $require = $this->app->request()->field('store_signup_require_email_verification') === '1';
        $this->settings->setStoreSignupEnabled($enabled);
        $this->settings->setRequireEmailVerification($require);
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'store.signup_toggle',
            'ok',
            null,
            null,
            ['enabled' => $enabled, 'require_email_verification' => $require],
        );
        $this->app->redirect(
            '/admin/signup',
            $enabled
                ? ($require
                    ? 'Public store signup is on. Email confirmation is required.'
                    : 'Public store signup is on. Email confirmation is off.')
                : 'Public store signup is off.'
        );
    }
}
