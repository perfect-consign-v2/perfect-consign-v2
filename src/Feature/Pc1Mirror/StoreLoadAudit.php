<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;

final class StoreLoadAudit
{
    /**
     * @return array{id: string, email: string}
     */
    public function requireAdmin(PDO $pdo, string $email): array
    {
        $email = strtolower(trim($email));
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            throw new MirrorException('name the platform admin with --admin=email');
        }
        $statement = $pdo->prepare(
            'SELECT id::text AS id, email FROM platform.admins WHERE lower(email) = ?'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        $stored = is_array($row) ? ($row['email'] ?? null) : null;
        if (!is_string($id) || !is_string($stored)) {
            throw new MirrorException('that platform admin was not found');
        }

        return ['id' => $id, 'email' => $stored];
    }

    /**
     * @param array{id: string, email: string} $admin
     */
    public function record(PDO $pdo, array $admin, string $tenantId, string $extractPath): void
    {
        $audit = new AdminAudit(
            $pdo,
            new Request('POST', '/pc1-mirror', [], [], '', '', ''),
            new RequestId('store-load'),
        );
        $audit->record(
            $admin['id'],
            $admin['email'],
            'store.load',
            'ok',
            $tenantId,
            MirrorConstants::TARGET_SLUG,
            ['extract' => $extractPath],
        );
    }

    public function tenantId(PDO $pdo, string $schema): string
    {
        $statement = $pdo->prepare(
            'SELECT id::text AS id FROM platform.tenants WHERE schema_name = ?'
        );
        $statement->execute([$schema]);
        $id = $statement->fetchColumn();
        if (!is_string($id) || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new MirrorException('the loaded store was not found');
        }

        return $id;
    }
}
