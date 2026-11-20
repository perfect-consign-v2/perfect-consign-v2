<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Database\Statements;

final class JournalAudit
{
    public function __construct(
        private readonly Actor $actor,
        private readonly AuditLog $audit,
        private readonly AdminSession $admins,
        private readonly Statements $statements,
    ) {
    }

    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?AuditAuthority $authority = null,
    ): void {
        $this->audit->record(
            AuditSubject::capture($this->actor, $this->admins, $this->audit->address()),
            $action,
            $entityType,
            $entityId,
            $authority,
        );
    }

    public function within(callable $work): void
    {
        $this->statements->transaction($work);
    }

    public function on(string $action): \Closure
    {
        return function (string $journalId) use ($action): void {
            $this->record($action, 'journal', $journalId);
        };
    }
}
