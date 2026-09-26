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
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $row;
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

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    public function post(
        string $postingKey,
        string $postingDate,
        string $sourceType,
        string $sourceReference,
        string $description,
        array $lines,
        bool $reversal = false,
        ?string $reversesJournalId = null,
    ): string {
        try {
            $this->pdo->beginTransaction();
            $book = $this->pdo->query('SELECT id, currency FROM ledger_books WHERE is_default');
            if ($book === false) {
                throw new DatabaseRefusal('the mall book is missing');
            }
            $bookRow = $book->fetch();
            if (!is_array($bookRow)) {
                throw new DatabaseRefusal('the mall book is missing');
            }
            $period = $this->pdo->prepare(
                "SELECT id FROM accounting_periods
                 WHERE book_id = ?::uuid AND status = 'open' AND period_range @> ?::date"
            );
            $period->execute([$bookRow['id'], $postingDate]);
            $periodId = $period->fetchColumn();
            if (!is_string($periodId)) {
                throw new DatabaseRefusal('no open period covers that date');
            }
            $idStatement = $this->pdo->query('SELECT uuidv7() AS id');
            if ($idStatement === false) {
                throw new DatabaseRefusal('could not create a journal id');
            }
            $journalId = $idStatement->fetchColumn();
            if (!is_string($journalId)) {
                throw new DatabaseRefusal('could not create a journal id');
            }
            $debit = 0;
            $credit = 0;
            foreach ($lines as $line) {
                $debit += $line['debit'];
                $credit += $line['credit'];
            }
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
                $bookRow['id'],
                $postingKey,
                $postingDate,
                $periodId,
                $bookRow['currency'],
                $sourceType,
                $sourceReference,
                $description,
                $reversal ? 'true' : 'false',
                $reversesJournalId,
                $debit,
                $credit,
            ]);
            $lineInsert = $this->pdo->prepare(
                'INSERT INTO journal_lines (
                    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
                 ) VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?, ?, ?, ?)'
            );
            $number = 1;
            foreach ($lines as $line) {
                $lineInsert->execute([
                    $journalId,
                    $bookRow['id'],
                    $number,
                    $line['account_id'],
                    $line['debit'],
                    $line['credit'],
                    $line['subledger_type'],
                    $line['subledger_ref'],
                ]);
                $number++;
            }
            $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
            $this->pdo->commit();

            return $journalId;
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw DatabaseRefusal::fromPdo($error);
        } catch (DatabaseRefusal $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
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
