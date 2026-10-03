<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDO;
use PDOException;
use PerfectConsign\Shared\Database\Statements;

final class LedgerStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
        private readonly LedgerPeriods $periods,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return $this->statements->rows(
            'SELECT account.id, account.code, account.name, account.account_type,
                    COALESCE(sum(line.debit_minor), 0) AS debit_minor,
                    COALESCE(sum(line.credit_minor), 0) AS credit_minor
             FROM accounts AS account
             LEFT JOIN journal_lines AS line ON line.account_id = account.id
             GROUP BY account.id, account.code, account.name, account.account_type
             ORDER BY account.code'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function periods(): array
    {
        return $this->statements->rows(
            'SELECT id, starts_on, ends_on, status FROM accounting_periods ORDER BY starts_on'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function journals(): array
    {
        return $this->statements->rows(
            'SELECT id, journal_no, posting_date, posting_key, source_type, description, is_reversal
             FROM journals
             ORDER BY posting_date, journal_no'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function journal(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, journal_no, posting_date, posting_key, source_type, source_reference, description,
                    is_reversal, reverses_journal_id, debit_total_minor
             FROM journals WHERE id = ?'
        );
        $statement->execute([$id]);
        return $this->statements->assoc($statement->fetch());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(string $journalId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT line.line_no, line.account_id, account.code, account.name, line.debit_minor, line.credit_minor,
                    line.subledger_type, line.subledger_ref
             FROM journal_lines AS line
             JOIN accounts AS account ON account.id = line.account_id
             WHERE line.journal_id = ?
             ORDER BY line.line_no'
        );
        $statement->execute([$journalId]);

        return $this->statements->all($statement);
    }

    public function reversed(string $journalId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM journals WHERE reverses_journal_id = ?');
        $statement->execute([$journalId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function postableAccounts(bool $nonControlOnly): array
    {
        $sql = 'SELECT account.id, account.code, account.name, role.role
                FROM accounts AS account
                JOIN chart_roles AS role ON role.account_id = account.id
                WHERE account.is_postable = true';
        if ($nonControlOnly) {
            $sql .= " AND role.role IN (
                'cash', 'bank', 'card', 'commission_revenue', 'sales_tax_payable',
                'rent_revenue', 'operating_expense'
            )";
        }
        $sql .= ' ORDER BY account.code';

        return $this->statements->rows($sql);
    }

    public function roleAccount(string $role): string
    {
        $statement = $this->pdo->prepare('SELECT account_id FROM chart_roles WHERE role = ?');
        $statement->execute([$role]);
        $id = $statement->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new DatabaseRefusal('the chart has no ' . $role . ' account');
        }

        return $id;
    }

    public function newId(): string
    {
        $statement = $this->pdo->query('SELECT uuidv7() AS id');
        if ($statement === false) {
            throw new DatabaseRefusal('could not create an id');
        }
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            throw new DatabaseRefusal('could not create an id');
        }

        return $id;
    }

    public function addPeriod(string $startsOn, string $endsOn): void
    {
        $book = $this->bookId();
        $this->statements->change(
            'INSERT INTO accounting_periods (book_id, starts_on, ends_on, status) VALUES (?::uuid, ?::date, ?::date, ?)',
            [$book, $startsOn, $endsOn, 'open']
        );
    }

    public function post(JournalDraft $draft): string
    {
        return $this->commitJournal($draft);
    }

    private function commitJournal(JournalDraft $draft): string
    {
        $joined = $this->pdo->inTransaction();
        try {
            if (!$joined) {
                $this->pdo->beginTransaction();
            }
            $this->pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            $journalId = $this->insertJournal(
                $draft->postingKey,
                $draft->postingDate,
                $draft->source->type,
                $draft->source->reference,
                $draft->source->description,
                $draft->lines,
                $draft->reversesJournalId === null ? 'false' : 'true',
                $draft->reversesJournalId,
            );
            $this->linkSession($journalId, $draft->sessionId, $draft->checkNumber);
            if ($draft->beforeCommit !== null) {
                $finished = $draft->beforeCommit;
                $finished($journalId);
            }
            $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
            if (!$joined) {
                $this->pdo->commit();
            }

            return $journalId;
        } catch (PDOException $error) {
            if (!$joined) {
                $this->rollBackOpen();
            }
            throw DatabaseRefusal::fromPdo($error);
        } catch (DatabaseRefusal $error) {
            if (!$joined) {
                $this->rollBackOpen();
            }
            throw $error;
        }
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function insertJournal(
        string $postingKey,
        string $postingDate,
        string $sourceType,
        string $sourceReference,
        string $description,
        array $lines,
        string $reversal,
        ?string $reversesJournalId,
    ): string {
        $book = $this->defaultBook();
        $journalId = $this->newId();
        [$debit, $credit] = $this->totals($lines);
        $this->insertHeader($journalId, $book, $this->periods->idFor($book['id'], $postingDate), [
            'postingKey' => $postingKey,
            'postingDate' => $postingDate,
            'sourceType' => $sourceType,
            'sourceReference' => $sourceReference,
            'description' => $description,
            'reversal' => $reversal,
            'reversesJournalId' => $reversesJournalId,
            'debit' => $debit,
            'credit' => $credit,
        ]);
        $this->insertLines($journalId, $book['id'], $lines);

        return $journalId;
    }

    /**
     * @return array{id: string, currency: string}
     */
    private function defaultBook(): array
    {
        $book = $this->pdo->query('SELECT id, currency FROM ledger_books WHERE is_default');
        if ($book === false) {
            throw new DatabaseRefusal('the mall book is missing');
        }
        $row = $this->statements->assoc($book->fetch());
        if ($row === null) {
            throw new DatabaseRefusal('the mall book is missing');
        }

        return [
            'id' => $this->requiredString($row, 'id', 'the mall book is missing'),
            'currency' => $this->requiredString($row, 'currency', 'the mall book is missing'),
        ];
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     * @return array{0: int, 1: int}
     */
    private function totals(array $lines): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $debit += $line['debit'];
            $credit += $line['credit'];
        }

        return [$debit, $credit];
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function insertLines(string $journalId, string $bookId, array $lines): void
    {
        $lineInsert = $this->pdo->prepare(
            'INSERT INTO journal_lines (
                journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
             ) VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?, ?, ?, ?)'
        );
        $number = 1;
        foreach ($lines as $line) {
            $lineInsert->execute([
                $journalId,
                $bookId,
                $number,
                $line['account_id'],
                $line['debit'],
                $line['credit'],
                $line['subledger_type'],
                $line['subledger_ref'],
            ]);
            $number++;
        }
    }

    /**
     * @param array{id: string, currency: string} $book
     * @param array{
     *     postingKey: string,
     *     postingDate: string,
     *     sourceType: string,
     *     sourceReference: string,
     *     description: string,
     *     reversal: string,
     *     reversesJournalId: ?string,
     *     debit: int,
     *     credit: int
     * } $header
     */
    private function insertHeader(string $journalId, array $book, string $periodId, array $header): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
                debit_total_minor, credit_total_minor
             ) VALUES (
                ?::uuid, ?::uuid, ?, ?::date, clock_timestamp(), ?::uuid,
                ?, ?, ?, ?, ?, ?::uuid, ?, ?
             )'
        );
        $insert->execute([
            $journalId,
            $book['id'],
            $header['postingKey'],
            $header['postingDate'],
            $periodId,
            $book['currency'],
            $header['sourceType'],
            $header['sourceReference'],
            $header['description'],
            $header['reversal'],
            $header['reversesJournalId'],
            $header['debit'],
            $header['credit'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requiredString(array $row, string $key, string $message): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new DatabaseRefusal($message);
        }

        return $value;
    }

    private function linkSession(string $journalId, ?string $sessionId, ?string $checkNumber): void
    {
        if ($sessionId !== null) {
            $link = $this->pdo->prepare(
                'INSERT INTO register_session_posts (session_id, journal_id) VALUES (?::uuid, ?::uuid)'
            );
            $link->execute([$sessionId, $journalId]);
        }
        if ($checkNumber === null) {
            return;
        }
        $check = $this->pdo->prepare(
            'INSERT INTO register_checks (journal_id, check_number) VALUES (?::uuid, ?)'
        );
        $check->execute([$journalId, $checkNumber]);
    }

    private function rollBackOpen(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function bookId(): string
    {
        $book = $this->pdo->query('SELECT id FROM ledger_books WHERE is_default');
        if ($book === false) {
            throw new DatabaseRefusal('the mall book is missing');
        }
        $id = $book->fetchColumn();
        if (!is_string($id)) {
            throw new DatabaseRefusal('the mall book is missing');
        }

        return $id;
    }
}
