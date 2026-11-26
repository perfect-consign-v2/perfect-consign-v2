<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Feature\Signup\SignupAdmin;
use RuntimeException;

final class AdminTenantCreateController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly SignupAdmin $signups,
    ) {
    }

    #[AdminRoute('/tenants/create', ['GET'])]
    public function form(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/tenant-create', [
            'title' => 'Create store',
            'error' => null,
            'storeName' => '',
            'email' => '',
        ]);
    }

    #[AdminRoute('/tenants/create', ['POST'])]
    public function create(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $request = $this->app->request();
        $storeName = $request->field('store_name');
        $email = $request->field('email');
        try {
            $owner = $this->signups->create(
                $storeName,
                $email,
                $request->field('password'),
                $request->field('password_confirm'),
                $admin,
            );
        } catch (RuntimeException $error) {
            $this->app->show('Feature/Admin/tenant-create', [
                'title' => 'Create store',
                'error' => $error->getMessage(),
                'storeName' => $storeName,
                'email' => $email,
            ]);

            return;
        }
        $this->app->redirect(
            $this->app->url('/signup'),
            'The store is being built. ' . $owner . ' gets an email when it is ready.'
        );
    }
}
