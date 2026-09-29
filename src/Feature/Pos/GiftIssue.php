<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class GiftIssue
{
    /** @var list<string> */
    private const TENDERS = ['cash', 'card', 'checks'];

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
    ) {
    }

    public function issue(string $session, string $date, string $number, int $amount, string $tender, ?string $check): string
    {
        $number = $this->number($number);
        $this->tender($amount, $tender, $check);
        $created = $this->statements->bound(
            "INSERT INTO stored_values (kind, number) VALUES ('gift_certificate', ?) RETURNING id",
            [$number]
        );
        $storedId = RowValue::string($created[0]['id'] ?? null);
        try {
            return $this->ledger->post(new JournalDraft(
                'gift-certificate:' . strtolower($number),
                $date,
                new JournalSource('gift_certificate_issue', $number, 'Gift certificate'),
                [
                    PostedLines::entry($this->ledger->roleAccount($tender), $amount, 0, null, null),
                    PostedLines::entry($this->ledger->roleAccount('gift_certificate'), 0, $amount, 'gift_certificate', $storedId),
                ],
                sessionId: $session,
                checkNumber: $tender === 'checks' ? $check : null,
            ));
        } catch (\Throwable $error) {
            $this->statements->change(
                'DELETE FROM stored_values WHERE id = ?::uuid
                 AND NOT EXISTS (SELECT 1 FROM journal_lines WHERE subledger_ref = ?::uuid)',
                [$storedId, $storedId]
            );
            throw $error;
        }
    }

    public function balance(string $number): ?int
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }
        $rows = $this->statements->bound(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS balance
             FROM stored_values AS stored
             LEFT JOIN journal_lines AS line
               ON line.subledger_ref = stored.id::text
              AND line.subledger_type = 'gift_certificate'
             WHERE stored.kind = 'gift_certificate'
               AND lower(stored.number) = lower(?)
             GROUP BY stored.id",
            [$number]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::integer($rows[0]['balance'] ?? null);
    }

    private function number(string $number): string
    {
        $number = trim($number);
        if ($number === '' || strlen($number) > 40) {
            throw new DatabaseRefusal('a stored value number is required');
        }

        return $number;
    }

    private function tender(int $amount, string $tender, ?string $check): void
    {
        if ($amount < 1 || !in_array($tender, self::TENDERS, true)) {
            throw new DatabaseRefusal('stored value is issued for cash, card, or a check');
        }
        if ($tender === 'checks' && ($check === null || $check === '')) {
            throw new DatabaseRefusal('a check sale needs a check number');
        }
    }
}
