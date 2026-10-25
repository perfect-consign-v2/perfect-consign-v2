<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;

final class AdminTenantController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly AdminTenantList $tenants,
        private readonly AdminEnterAs $enterAs,
        private readonly AdminTenantWipe $wipe,
    ) {
    }

    #[Route('/admin/tenants', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/tenants', [
            'title' => 'Tenants',
            'tenants' => $this->tenants->all(),
        ]);
    }

    #[Route('/admin/tenants/([a-z][a-z0-9-]{0,62})/enter', ['POST'])]
    public function enter(string $slug): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->app->redirect('/admin/tenants', null, 'That store was not found.');
        }
        $role = $this->app->request()->field('role');
        try {
            $path = $this->enterAs->enter($admin, $tenant, $role);
        } catch (\InvalidArgumentException $error) {
            $this->app->redirect('/admin/tenants', null, $error->getMessage());
        }
        $this->app->redirect($path);
    }

    #[Route('/admin/tenants/([a-z][a-z0-9-]{0,62})/delete', ['GET'])]
    public function deleteForm(string $slug): void
    {
        $this->app->requireAdmin();
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->app->redirect('/admin/tenants', null, 'That store was not found.');
        }
        $this->app->show('Feature/Admin/delete', [
            'title' => 'Delete ' . $tenant->displayName,
            'tenant' => $tenant,
            'error' => null,
        ]);
    }

    #[Route('/admin/tenants/([a-z][a-z0-9-]{0,62})/delete', ['POST'])]
    public function deleteSubmit(string $slug): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->app->redirect('/admin/tenants', null, 'That store was not found.');
        }
        try {
            $this->wipe->wipe($admin, $tenant, $this->app->request()->field('confirm_slug'));
        } catch (\InvalidArgumentException $error) {
            $this->app->show('Feature/Admin/delete', [
                'title' => 'Delete ' . $tenant->displayName,
                'tenant' => $tenant,
                'error' => $error->getMessage(),
            ]);

            return;
        } catch (\RuntimeException $error) {
            $this->app->show('Feature/Admin/delete', [
                'title' => 'Delete ' . $tenant->displayName,
                'tenant' => $tenant,
                'error' => $error->getMessage(),
            ]);

            return;
        }
        $this->app->redirect('/admin/tenants', 'Deleted ' . $slug . '.');
    }
}
