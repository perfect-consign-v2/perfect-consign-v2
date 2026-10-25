<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use RuntimeException;

final class AdminTenantWipe
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminAudit $audit,
    ) {
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    public function wipe(array $admin, TenantRecord $tenant, string $confirmation): void
    {
        if ($confirmation !== $tenant->slug) {
            $this->audit->record(
                $admin['id'],
                $admin['email'],
                'tenant.delete_refused',
                'refused',
                $tenant->id,
                $tenant->slug,
                ['reason' => 'slug_mismatch'],
            );
            throw new \InvalidArgumentException('Type the store slug exactly to confirm deletion.');
        }
        $resets = $this->pdo->prepare(
            'SELECT 1 FROM platform.password_resets WHERE tenant_id = ?::uuid LIMIT 1'
        );
        $resets->execute([$tenant->id]);
        if ($resets->fetchColumn() !== false) {
            $this->audit->record(
                $admin['id'],
                $admin['email'],
                'tenant.delete_refused',
                'refused',
                $tenant->id,
                $tenant->slug,
                ['reason' => 'password_resets'],
            );
            throw new \InvalidArgumentException(
                'This store still has password reset records. Those must age out before it can be deleted.'
            );
        }
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'tenant.delete',
            'ok',
            $tenant->id,
            $tenant->slug,
            ['display_name' => $tenant->displayName],
        );
        $statement = $this->pdo->prepare('SELECT platform.wipe_tenant(?)');
        try {
            $statement->execute([$tenant->slug]);
        } catch (\PDOException $error) {
            throw new RuntimeException($error->getMessage(), 0, $error);
        }
    }
}
