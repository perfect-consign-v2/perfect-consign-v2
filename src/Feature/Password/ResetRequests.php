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
        $address = $this->request->address;
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT pg_advisory_xact_lock(87211, hashtext(?)), pg_advisory_xact_lock(87212, hashtext(?))'
            );
            $lock->execute([$tenant->id . ':' . $username, $address]);
            $this->prune();
            if ($this->recentForIdentity($tenant->id, $username) >= 5 || $this->recentForClient() >= 40) {
                $this->pdo->commit();

                return false;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.password_reset_requests (tenant_id, username, client_address)
                 VALUES (?::uuid, ?, ?)'
            );
            $insert->execute([$tenant->id, $username, $address]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
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
