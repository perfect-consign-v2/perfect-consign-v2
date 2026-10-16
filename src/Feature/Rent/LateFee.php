<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class LateFee
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
    ) {
    }

    public function post(string $boothId, string $period, string $date, int $minor): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $boothId) !== 1) {
            throw new DatabaseRefusal('Choose a booth.');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])(-\d{2})?$/', $period) !== 1) {
            throw new DatabaseRefusal('Enter the late fee.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new DatabaseRefusal('Enter the date of the late fee.');
        }
        if ($minor < 1) {
            throw new DatabaseRefusal('Enter the late fee.');
        }
        $key = 'late-fee:' . $boothId . ':' . $period;
        $existing = $this->statements->bound('SELECT id FROM journals WHERE posting_key = ?', [$key]);
        $id = $existing[0]['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        return $this->ledger->post(new JournalDraft(
            $key,
            $date,
            new JournalSource('late_fee', $boothId . ':' . $period, 'Late fee'),
            [
                PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), $minor, 0, 'booth', $boothId),
                PostedLines::entry($this->ledger->roleAccount('late_fee_income'), 0, $minor, null, null),
            ],
        ));
    }

    public function waive(string $journalId, string $date, string $note = ''): string
    {
        $note = $this->note($note);
        $this->requireFee($journalId, $date);
        $journal = $this->ledger->journal($journalId);
        if ($journal === null || RowValue::string($journal['source_type'] ?? null) !== 'late_fee') {
            throw new DatabaseRefusal('Choose a late fee.');
        }
        if ($this->flag($journal['is_reversal'] ?? null) || $this->ledger->reversed($journalId)) {
            throw new DatabaseRefusal('That late fee is already waived.');
        }
        $posted = RowValue::string($journal['posting_date'] ?? null);
        if ($date < $posted) {
            $date = $posted;
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'reversal:' . $journalId,
            postingDate: $date,
            source: new JournalSource(
                'late_fee',
                RowValue::string($journal['source_reference'] ?? null) . ':waive',
                $note === '' ? 'Late fee waived' : 'Late fee waived. ' . $note,
            ),
            lines: $this->swapped($journalId),
            reversesJournalId: $journalId,
        ));
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function swapped(string $journalId): array
    {
        $lines = [];
        foreach ($this->ledger->lines($journalId) as $line) {
            $lines[] = PostedLines::entry(
                RowValue::string($line['account_id'] ?? null),
                RowValue::integer($line['credit_minor'] ?? null),
                RowValue::integer($line['debit_minor'] ?? null),
                RowValue::optionalString($line['subledger_type'] ?? null),
                RowValue::optionalString($line['subledger_ref'] ?? null),
            );
        }
        if (count($lines) < 2) {
            throw new DatabaseRefusal('Choose a late fee.');
        }

        return $lines;
    }

    private function note(string $note): string
    {
        $note = trim($note);
        if (strlen($note) > 500) {
            throw new DatabaseRefusal('Enter a shorter waive note.');
        }

        return $note;
    }

    private function requireFee(string $journalId, string $date): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $journalId) !== 1) {
            throw new DatabaseRefusal('Choose a late fee.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new DatabaseRefusal('Enter the date of the late fee.');
        }
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
