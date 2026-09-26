<?php

declare(strict_types=1);

namespace PerfectConsign\Tenant;

final class TenantRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $schema,
        public readonly string $role,
        public readonly string $displayName,
    ) {
    }

    public static function roleFor(string $slug, string $schema): string
    {
        if ($slug === 'mall') {
            return 'stall_poster';
        }

        return 'poster_' . substr($schema, strlen('tenant_'));
    }
}
