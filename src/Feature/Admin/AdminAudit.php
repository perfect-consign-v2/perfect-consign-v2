<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;
use PDO;
use RuntimeException;

final class AdminAudit
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly RequestId $requestId,
    ) {
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function record(
        ?string $adminId,
        string $adminEmail,
        string $action,
        string $outcome,
        ?string $tenantId = null,
        ?string $slug = null,
        array $detail = [],
    ): void {
        if ($adminEmail === '' || $action === '' || $outcome === '') {
            throw new RuntimeException('the admin audit row is incomplete');
        }
        $encoded = json_encode($detail, JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.admin_audit (
                admin_id, admin_email, action, outcome, tenant_id, slug, detail, request_id, client_address
             ) VALUES (
                ?::uuid, ?, ?, ?, ?::uuid, ?, ?::jsonb, ?, ?
             )'
        );
        $statement->execute([
            $adminId,
            $adminEmail,
            $action,
            $outcome,
            $tenantId,
            $slug,
            $encoded,
            $this->requestId->value,
            $this->request->address,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('the admin audit row was not stored');
        }
    }
}
