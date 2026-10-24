<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

final class JournalAudit
{
    public function __construct(
        private readonly Actor $actor,
        private readonly AuditLog $audit,
    ) {
    }

    public function record(string $action, string $entityId, string $entityType = 'journal'): void
    {
        $this->audit->record($this->actor->name(), $this->actor->role(), $action, $entityType, $entityId);
    }

    public function on(string $action): \Closure
    {
        return function (string $journalId) use ($action): void {
            $this->record($action, $journalId);
        };
    }
}
