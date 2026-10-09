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
    public const CHECK_REQUIRED = 'a check sale needs a check number';

    public const NUMBER_REQUIRED = 'Enter the certificate number.';

    /** @var list<string> */
    private const TENDERS = ['cash', 'card', 'checks'];

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
    ) {
    }

    public function issue(string $session, string $date, string $number, int $amount, string $tender, ?string $check, string $kind = 'gift_certificate'): string
    {
        $kind = $this->kind($kind);
        $requested = $this->number($number);
        $this->tender($amount, $tender, $check);
        $attempt = 0;
        $refusal = new DatabaseRefusal(DatabaseRefusal::CERTIFICATE_NUMBER_USED);
        while ($attempt < 5) {
            $attempt++;
            $assigned = $requested !== '' ? $requested : $this->allocate($kind);
            $journalId = '';
            try {
                $this->statements->isolated(function () use ($session, $date, $assigned, $amount, $tender, $check, $kind, &$journalId): void {
                    $journalId = $this->store($session, $date, $assigned, $amount, $tender, $check, $kind);
                });
                if ($journalId === '') {
                    throw new DatabaseRefusal('that journal is not a gift certificate');
                }

                return $journalId;
            } catch (DatabaseRefusal $error) {
                $refusal = $error;
                if ($requested !== '' || $error->getMessage() !== DatabaseRefusal::CERTIFICATE_NUMBER_USED) {
                    throw $error;
                }
            }
        }

        throw $refusal;
    }

    public function balance(string $number, string $kind = 'gift_certificate'): ?int
    {
        $number = trim($number);
        $kind = $this->kind($kind);
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
        $kind = $this->kindFromSource(is_string($source) ? $source : '');
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
            'label' => $this->label($kind),
        ];
    }

    private function store(string $session, string $date, string $number, int $amount, string $tender, ?string $check, string $kind): string
    {
        $created = $this->statements->bound(
            'INSERT INTO stored_values (kind, number) VALUES (?, ?) RETURNING id',
            [$kind, $number]
        );
        $storedId = RowValue::string($created[0]['id'] ?? null);
        try {
            return $this->ledger->post(new JournalDraft(
                str_replace('_', '-', $kind) . ':' . strtolower($number),
                $date,
                new JournalSource($kind . '_issue', $number, $this->label($kind)),
                [
                    PostedLines::entry($this->ledger->roleAccount($tender), $amount, 0, null, null),
                    PostedLines::entry($this->ledger->roleAccount($kind), 0, $amount, $kind, $storedId),
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

    private function allocate(string $kind): string
    {
        $prefix = $this->prefix($kind);
        $rows = $this->statements->bound(
            'SELECT upper(number) AS number FROM stored_values
             WHERE kind = ? AND number ~* ?
             ORDER BY char_length(number) DESC, upper(number) DESC
             LIMIT 1',
            [$kind, '^' . $prefix . '-[0-9]+$']
        );
        $last = $rows[0]['number'] ?? null;
        $sequence = 1001;
        if (is_string($last) && preg_match('/^' . $prefix . '-(\d+)$/i', $last, $parts) === 1) {
            if (strlen($parts[1]) > 12) {
                throw new DatabaseRefusal('a stored value number is required');
            }
            $sequence = ((int) $parts[1]) + 1;
        }
        if ($sequence < 1001) {
            $sequence = 1001;
        }
        $number = $prefix . '-' . $sequence;
        if (strlen($number) > 40) {
            throw new DatabaseRefusal('a stored value number is required');
        }

        return $number;
    }

    private function number(string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }
        if (strlen($number) > 40) {
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
            throw new DatabaseRefusal(self::CHECK_REQUIRED);
        }
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

    private function kind(string $kind): string
    {
        if (!in_array($kind, ['gift_certificate', 'gift_card', 'store_credit'], true)) {
            throw new DatabaseRefusal('Choose a gift certificate, a gift card, or store credit.');
        }

        return $kind;
    }

    private function kindFromSource(string $source): ?string
    {
        return match ($source) {
            'gift_certificate_issue' => 'gift_certificate',
            'gift_card_issue' => 'gift_card',
            'store_credit_issue' => 'store_credit',
            default => null,
        };
    }

    private function prefix(string $kind): string
    {
        return match ($kind) {
            'gift_card' => 'GD',
            'store_credit' => 'SC',
            default => 'GC',
        };
    }

    private function label(string $kind): string
    {
        return match ($kind) {
            'gift_card' => 'Gift card',
            'store_credit' => 'Store credit',
            default => 'Gift certificate',
        };
    }
}
