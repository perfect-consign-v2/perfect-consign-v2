<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutReverser
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
        private readonly Actor $actor,
        private readonly JournalAudit $audit,
    ) {
    }

    public function undo(string $runId, string $postingDate, string $reason): string
    {
        $actor = $this->actor->username();
        if ($actor === '' || trim($reason) === '') {
            throw new DatabaseRefusal('a payout reversal needs the operator and a reason');
        }
        $journalId = '';
        $this->audit->within(function () use ($runId, $postingDate, $actor, $reason, &$journalId): void {
            $rows = $this->statements->bound(
                'SELECT stall_reverse_payout_run(?::uuid, ?::date, ?, ?) AS id',
                [$runId, $postingDate, $actor, trim($reason)]
            );
            $journalId = RowValue::string($rows[0]['id'] ?? null);
            $this->audit->record('payout.undo', 'payout', $runId);
        });

        return $journalId;
    }

    public function reverse(string $journalId, string $postingDate): string
    {
        $journal = $this->ledger->journal($journalId);
        if ($journal === null) {
            throw new \InvalidArgumentException('that payout journal is not in this mall');
        }
        $lines = [];
        foreach ($this->ledger->lines($journalId) as $line) {
            $lines[] = PostedLines::entry(
                RowValue::string($line['account_id'] ?? null),
                RowValue::integer($line['credit_minor'] ?? null),
                RowValue::integer($line['debit_minor'] ?? null),
                is_string($line['subledger_type'] ?? null) ? $line['subledger_type'] : null,
                is_string($line['subledger_ref'] ?? null) ? $line['subledger_ref'] : null,
            );
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'reversal:' . $journalId,
            postingDate: $postingDate,
            source: new JournalSource(
                RowValue::string($journal['source_type'] ?? null),
                RowValue::string($journal['source_reference'] ?? null),
                'Reversal'
            ),
            lines: $lines,
            reversesJournalId: $journalId,
        ));
    }
}
