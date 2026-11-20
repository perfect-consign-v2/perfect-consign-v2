<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

final class TenantDeletePolicy
{
    public function requiresCountersign(): bool
    {
        $env = getenv('APP_ENV');

        return is_string($env) && in_array(strtolower(trim($env)), ['production', 'prod'], true);
    }
}
