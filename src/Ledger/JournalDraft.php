<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

final class JournalDraft
{
    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    public function __construct(
        public readonly string $postingKey,
        public readonly string $postingDate,
        public readonly JournalSource $source,
        public readonly array $lines,
        public readonly ?string $reversesJournalId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $checkNumber = null,
        public readonly ?\Closure $beforeCommit = null,
        public readonly ?int $tenderedMinor = null,
    ) {
    }
}
