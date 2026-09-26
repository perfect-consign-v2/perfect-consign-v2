<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDO;
use PDOException;

final class LedgerStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return $this->rows(
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
        return $this->rows(
            'SELECT id, starts_on, ends_on, status FROM accounting_periods ORDER BY starts_on'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function journals(): array
    {
        return $this->rows(
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

        return $this->all($statement);
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

        return $this->rows($sql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendors(): array
    {
        return $this->rows('SELECT id, code, name FROM vendors ORDER BY code');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function booths(): array
    {
        return $this->rows('SELECT id, code, name FROM booths ORDER BY code');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(): array
    {
        return $this->rows(
            'SELECT assignment.id, booth.code AS booth_code, vendor.code AS vendor_code,
                    assignment.starts_on, assignment.ends_on
             FROM booth_assignments AS assignment
             JOIN booths AS booth ON booth.id = assignment.booth_id
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             ORDER BY assignment.starts_on, booth.code'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rates(): array
    {
        return $this->rows(
            'SELECT booth.code, rate.month_start, rate.amount_minor
             FROM booth_rent_rates AS rate
             JOIN booths AS booth ON booth.id = rate.booth_id
             ORDER BY rate.month_start, booth.code'
        );
    }

    public function openTax(): int
    {
        $value = $this->pdo->query(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'sales_tax_payable'"
        );
        if ($value === false) {
            return 0;
        }

        return (int) $value->fetchColumn();
    }

    public function openingAllowed(): bool
    {
        $value = $this->pdo->query(
            "SELECT count(*) FROM journals AS other
             WHERE other.posting_key <> 'opening'
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS opened
                   WHERE opened.id = other.reverses_journal_id
                     AND opened.posting_key = 'opening'
               )"
        );
        if ($value === false) {
            return false;
        }
        $others = (int) $value->fetchColumn();
        $live = $this->pdo->query(
            "SELECT count(*) FROM journals AS prior
             WHERE prior.posting_key = 'opening'
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = prior.id
               )"
        );
        if ($live === false) {
            return false;
        }

        return $others === 0 && (int) $live->fetchColumn() === 0;
    }

    /**
     * @return array{payable: int, rent: int, booths: list<array{booth_id: string, open_minor: int}>}
     */
    public function openPayout(string $vendorId): array
    {
        $payable = $this->pdo->prepare(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'vendor_payable'
               AND line.subledger_type = 'vendor'
               AND line.subledger_ref = ?"
        );
        $payable->execute([$vendorId]);
        $rent = $this->pdo->prepare(
            "SELECT line.subledger_ref AS booth_id, sum(line.debit_minor - line.credit_minor) AS open_minor
             FROM journal_lines AS line
             JOIN journals AS posted ON posted.id = line.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
               AND EXISTS (
                   SELECT 1 FROM booth_assignments AS assignment
                   WHERE assignment.vendor_id = ?::uuid
                     AND assignment.booth_id = line.subledger_ref::uuid
                     AND assignment.assignment_range @> posted.posting_date
               )
             GROUP BY line.subledger_ref
             HAVING sum(line.debit_minor - line.credit_minor) > 0"
        );
        $rent->execute([$vendorId]);
        $booths = [];
        $rentTotal = 0;
        foreach ($this->all($rent) as $row) {
            $open = (int) $row['open_minor'];
            $booths[] = ['booth_id' => (string) $row['booth_id'], 'open_minor' => $open];
            $rentTotal += $open;
        }

        return [
            'payable' => (int) $payable->fetchColumn(),
            'rent' => $rentTotal,
            'booths' => $booths,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statement(string $vendorId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT posting_date, journal_no, source_type, posting_key, role, debit_minor, credit_minor
             FROM vendor_statement
             WHERE vendor_id = ?::uuid
             ORDER BY posting_date, journal_no, line_no'
        );
        $statement->execute([$vendorId]);

        return $this->all($statement);
    }

    public function rate(string $boothId, string $date): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT amount_minor FROM booth_rent_rates
             WHERE booth_id = ?::uuid AND month_start = date_trunc('month', ?::date)::date"
        );
        $statement->execute([$boothId, $date]);
        $amount = $statement->fetchColumn();
        if ($amount === false) {
            return null;
        }

        return (int) $amount;
    }

    /**
     * @return list<array{account_id: string, net_minor: int}>
     */
    public function periodMovement(string $periodId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT account.id AS account_id, sum(line.credit_minor - line.debit_minor) AS net_minor
             FROM journal_lines AS line
             JOIN journals AS posted ON posted.id = line.journal_id
             JOIN accounts AS account ON account.id = line.account_id
             WHERE posted.period_id = ?::uuid
               AND account.account_type IN ('revenue', 'expense')
             GROUP BY account.id
             HAVING sum(line.credit_minor - line.debit_minor) <> 0"
        );
        $statement->execute([$periodId]);
        $rows = [];
        foreach ($this->all($statement) as $row) {
            $rows[] = [
                'account_id' => (string) $row['account_id'],
                'net_minor' => (int) $row['net_minor'],
            ];
        }

        return $rows;
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

    public function exportCsv(string $system, string $from, string $to, string $taxRate): string
    {
        try {
            $statement = $this->pdo->prepare('SELECT stall_export_journal_csv(?, ?::date, ?::date, ?)');
            $statement->execute([$system, $from, $to, $taxRate]);
            $lines = $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
        $csv = '';
        foreach ($lines as $line) {
            if (!is_string($line)) {
                throw new DatabaseRefusal('the export returned a non-text line');
            }
            $csv .= $line . "\n";
        }

        return $csv;
    }

    public function addVendor(string $code, string $name): void
    {
        $this->change('INSERT INTO vendors (code, name) VALUES (?, ?)', [$code, $name]);
    }

    public function addBooth(string $code, string $name): void
    {
        $this->change('INSERT INTO booths (code, name) VALUES (?, ?)', [$code, $name]);
    }

    public function addAssignment(string $boothId, string $vendorId, string $startsOn, string $endsOn): void
    {
        if ($endsOn === '') {
            $this->change(
                'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date)',
                [$boothId, $vendorId, $startsOn]
            );
            return;
        }
        $this->change(
            'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, ?::date, ?::date)',
            [$boothId, $vendorId, $startsOn, $endsOn]
        );
    }

    public function endAssignment(string $id, string $endsOn): void
    {
        $this->change(
            'UPDATE booth_assignments SET ends_on = ?::date WHERE id = ?::uuid',
            [$endsOn, $id]
        );
    }

    public function addRate(string $boothId, string $monthStart, int $amount): void
    {
        if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $monthStart, $matches) === 1) {
            $monthStart = $matches[1] . '-01';
        }
        $this->change(
            'INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES (?::uuid, ?::date, ?)',
            [$boothId, $monthStart, $amount]
        );
    }

    public function addPeriod(string $startsOn, string $endsOn): void
    {
        $book = $this->bookId();
        $this->change(
            'INSERT INTO accounting_periods (book_id, starts_on, ends_on, status) VALUES (?::uuid, ?::date, ?::date, ?)',
            [$book, $startsOn, $endsOn, 'open']
        );
    }

    public function closeQuiet(string $periodId): void
    {
        $this->change('SELECT stall_close_quiet_period(?::uuid)', [$periodId]);
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

    /**
     * @param list<mixed> $params
     */
    private function change(string $sql, array $params): void
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return [];
        }

        return $this->all($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(\PDOStatement $statement): array
    {
        $rows = [];
        foreach ($statement as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
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
