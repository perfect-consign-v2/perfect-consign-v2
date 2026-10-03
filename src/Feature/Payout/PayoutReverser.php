<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutReverser
{
    public function __construct(private readonly LedgerStore $ledger)
    {
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
