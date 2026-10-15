<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class ResetRequests
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allow(TenantRecord $tenant, string $username): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.password_reset_requests
             WHERE tenant_id = ?::uuid
               AND username = ?
               AND requested_at > clock_timestamp() - interval '15 minutes'"
        );
        $statement->execute([$tenant->id, $username]);
        if ((int) $statement->fetchColumn() >= 5) {
            return false;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO platform.password_reset_requests (tenant_id, username) VALUES (?::uuid, ?)'
        );
        $insert->execute([$tenant->id, $username]);

        return true;
    }
}
