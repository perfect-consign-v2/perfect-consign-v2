<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class ResetRequests
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
    ) {
    }

    public function allow(TenantRecord $tenant, string $username): bool
    {
        $this->prune();
        if ($this->recentForIdentity($tenant->id, $username) >= 5) {
            return false;
        }
        if ($this->recentForClient() >= 40) {
            return false;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO platform.password_reset_requests (tenant_id, username, client_address)
             VALUES (?::uuid, ?, ?)'
        );
        $insert->execute([$tenant->id, $username, $this->request->address]);

        return true;
    }

    private function prune(): void
    {
        $this->pdo->exec(
            "DELETE FROM platform.password_reset_requests
             WHERE requested_at <= clock_timestamp() - interval '15 minutes'"
        );
    }

    private function recentForIdentity(string $tenantId, string $username): int
    {
        $statement = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.password_reset_requests
             WHERE tenant_id = ?::uuid
               AND username = ?
               AND requested_at > clock_timestamp() - interval '15 minutes'"
        );
        $statement->execute([$tenantId, $username]);

        return (int) $statement->fetchColumn();
    }

    private function recentForClient(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.password_reset_requests
             WHERE client_address = ?
               AND requested_at > clock_timestamp() - interval '15 minutes'"
        );
        $statement->execute([$this->request->address]);

        return (int) $statement->fetchColumn();
    }
}
