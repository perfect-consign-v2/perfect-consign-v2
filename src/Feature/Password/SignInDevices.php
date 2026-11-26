<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\SignInDevice;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

/**
 * The browsers a store account signed in from. A listed browser is not
 * refused when someone else trips the account-wide sign-in limit; removing it
 * means that browser needs the password like a new one. The list lives in
 * the platform schema, so each call runs as the app role and hands the
 * connection back to the store's role.
 */
final class SignInDevices
{
    public const GONE = 'That browser is not on your list any more.';

    public function __construct(
        private readonly PDO $pdo,
        private readonly SignInDevice $device,
        private readonly DatabaseRole $roles,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, created_at: string, last_seen_at: string, current: bool}>
     */
    public function listed(TenantRecord $tenant, string $username): array
    {
        $this->roles->reset();
        try {
            return $this->read($tenant->id, $username);
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    public function forget(TenantRecord $tenant, string $username, string $deviceId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $deviceId) !== 1) {
            throw new \InvalidArgumentException(self::GONE);
        }
        $this->roles->reset();
        try {
            $statement = $this->pdo->prepare('SELECT platform.sign_in_device_forget(?::uuid, ?::text, ?::uuid)');
            $statement->execute([$tenant->id, $username, $deviceId]);
            $gone = $statement->fetchColumn();
        } finally {
            $this->roles->assume($tenant->role);
        }
        if (!in_array($gone, [true, 't', 'true', 1, '1'], true)) {
            throw new \InvalidArgumentException(self::GONE);
        }
    }

    /**
     * @return list<array{id: string, label: string, created_at: string, last_seen_at: string, current: bool}>
     */
    private function read(string $tenantId, string $username): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, label, created_at::text AS created_at, last_seen_at::text AS last_seen_at, is_current
             FROM platform.sign_in_device_list(?::uuid, ?::text, ?::text)'
        );
        $statement->execute([$tenantId, $username, $this->device->hash()]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $rows[] = [
                'id' => $row['id'],
                'label' => is_string($row['label'] ?? null) && $row['label'] !== '' ? $row['label'] : 'Unknown browser',
                'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
                'last_seen_at' => is_string($row['last_seen_at'] ?? null) ? $row['last_seen_at'] : '',
                'current' => in_array($row['is_current'] ?? null, [true, 't', 'true', 1, '1'], true),
            ];
        }

        return $rows;
    }
}
