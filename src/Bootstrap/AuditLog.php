<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;

final class AuditLog
{
    public function __construct(
        private readonly Statements $statements,
        private readonly Request $request,
        private readonly RequestId $requestId,
    ) {
    }

    public function record(
        AuditSubject $subject,
        string $action,
        string $entityType,
        string $entityId,
        ?AuditAuthority $authority = null,
    ): void {
        if ($action === '' || $entityType === '' || $entityId === '') {
            throw new DatabaseRefusal('the audit row is incomplete');
        }
        $stored = $this->statements->affected(
            'INSERT INTO audit_events (
                actor, role, action, entity_type, entity_id, request_id,
                account_id, account_kind, username, client_address,
                authority_account_id, authority_username,
                entered_by_admin_id, entered_by_email
             ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?::uuid, ?, ?, ?,
                ?::uuid, ?,
                ?::uuid, ?
             )',
            [
                $subject->name,
                $subject->role,
                $action,
                $entityType,
                $entityId,
                $this->requestId->value,
                $subject->accountId,
                $subject->kind,
                $subject->username,
                $subject->clientAddress,
                $authority?->accountId,
                $authority?->username,
                $subject->enteredByAdminId,
                $subject->enteredByEmail,
            ],
        );
        if ($stored !== 1) {
            throw new DatabaseRefusal('the audit row was not stored');
        }
    }

    public function address(): string
    {
        return $this->request->address;
    }
}
