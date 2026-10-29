<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;
use PerfectConsign\Feature\Signup\StoreSignup;
use RuntimeException;

final class AdminTenantCreateController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly StoreSignup $signup,
    ) {
    }

    #[Route('/admin/tenants/create', ['GET'])]
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

    #[Route('/admin/tenants/create', ['POST'])]
    public function create(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $request = $this->app->request();
        $storeName = $request->field('store_name');
        $email = $request->field('email');
        try {
            $created = $this->signup->registerAdmin(
                $storeName,
                $email,
                $request->field('password'),
                $request->field('password_confirm'),
                $admin['id'],
                $admin['email'],
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
            '/admin/tenants',
            'Created ' . $created['slug'] . '. Owner can sign in with ' . $created['email'] . '.'
        );
    }
}
