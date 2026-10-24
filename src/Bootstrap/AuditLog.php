<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Database\Statements;

final class AuditLog
{
    private readonly string $requestId;

    public function __construct(private readonly Statements $statements)
    {
        $this->requestId = bin2hex(random_bytes(8));
    }

    public function record(string $actor, string $role, string $action, string $entityType, string $entityId): void
    {
        if ($actor === '' || $role === '' || $action === '' || $entityType === '' || $entityId === '') {
            return;
        }
        $this->statements->change(
            'INSERT INTO audit_events (actor, role, action, entity_type, entity_id, request_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$actor, $role, $action, $entityType, $entityId, $this->requestId]
        );
    }
}
