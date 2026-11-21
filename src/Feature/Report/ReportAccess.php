<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\Permission;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ReportAccess
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
    ) {
    }

    public function open(string $slug): TenantRecord
    {
        $tenant = $this->app->open($slug, true);
        $role = $this->actor->role();
        if (!Permission::allows($role, 'report.read') && !Permission::allows($role, 'settings.edit')) {
            $this->app->notFound();
        }

        return $tenant;
    }
}
