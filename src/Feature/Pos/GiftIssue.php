<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class GiftIssue
{
    public const CHECK_REQUIRED = 'a check sale needs a check number';

    public const NUMBER_REQUIRED = 'Enter the certificate number.';

    private readonly GiftIssuePosting $posting;

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
    ) {
        $this->posting = new GiftIssuePosting($statements, $ledger);
    }

    public function issue(
        string $session,
        string $date,
        string $number,
        int $amount,
        string $tender,
        ?string $check,
        string $kind = 'gift_certificate',
        string $formToken = '',
    ): string {
        return $this->posting->issue($session, $date, $number, $amount, $tender, $check, $kind, $formToken);
    }

    public function balance(string $number, string $kind = 'gift_certificate'): ?int
    {
        $number = trim($number);
        $kind = GiftKinds::normalize($kind);
        if ($number === '') {
            return null;
        }
        $rows = $this->statements->bound(
            'SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS balance
             FROM stored_values AS stored
             LEFT JOIN journal_lines AS line
               ON line.subledger_ref = stored.id::text
              AND line.subledger_type = ?
             WHERE stored.kind = ?
               AND lower(stored.number) = lower(?)
             GROUP BY stored.id',
            [$kind, $kind, $number]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::integer($rows[0]['balance'] ?? null);
    }

    /**
     * @return array{number: string, posted_on: string, amount: int, balance: int, voided: bool, label: string}
     */
    public function slip(string $journalId): array
    {
        $journal = $this->ledger->journal($journalId);
        if ($journal === null) {
            throw new DatabaseRefusal('that journal is not a gift certificate');
        }
        $reference = $journal['source_reference'] ?? null;
        $source = $journal['source_type'] ?? null;
        $kind = GiftKinds::fromSource(is_string($source) ? $source : '');
        if (
            !is_string($reference)
            || $kind === null
            || in_array($journal['is_reversal'] ?? null, [true, 1, '1', 't', 'true'], true)
        ) {
            throw new DatabaseRefusal('that journal is not a gift certificate');
        }
        $amount = $this->issuedAmount($journalId, $kind);
        $left = $this->balance($reference, $kind);
        if ($amount < 1 || $left === null) {
            throw new DatabaseRefusal('that journal is not a gift certificate');
        }
        $posted = $journal['posting_date'] ?? null;

        return [
            'number' => $reference,
            'posted_on' => $posted instanceof \DateTimeInterface ? $posted->format('Y-m-d') : RowValue::string($posted),
            'amount' => $amount,
            'balance' => $left,
            'voided' => $this->ledger->reversed($journalId),
            'label' => GiftKinds::label($kind),
        ];
    }

    private function issuedAmount(string $journalId, string $kind): int
    {
        foreach ($this->ledger->lines($journalId) as $line) {
            if (($line['subledger_type'] ?? null) !== $kind) {
                continue;
            }
            $credit = RowValue::integer($line['credit_minor'] ?? 0);
            if ($credit > 0) {
                return $credit;
            }
        }

        return 0;
    }
}
