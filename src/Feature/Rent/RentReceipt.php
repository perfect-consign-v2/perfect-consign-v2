<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RentReceipt
{
    private bool $ownsTransaction = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
        private readonly PayoutStore $payouts,
        private readonly MallPayoutSettings $settings,
        private readonly RentPosting $posting,
        private readonly RentSlip $slips,
        private readonly RentPayment $payments,
    ) {
    }

    /**
     * The amount the vendor page already shows. Pack owes when sales pay the rent,
     * otherwise that booth's open receivable. A late fee still on the booth is part of it.
     *
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string}|null
     */
    public function figure(string $vendorId, string $boothId, string $today): ?array
    {
        if ($this->settings->rentPrepaid()) {
            return $this->packFigure($vendorId, $boothId, $today);
        }

        return $this->openFigure($vendorId, $boothId, $today);
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     */
    public function collect(
        string $vendorId,
        string $boothId,
        string $today,
        string $sessionId,
        string $amount,
        array $tender,
    ): string {
        $figure = $this->figure($vendorId, $boothId, $today);
        if ($figure === null) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $shaped = $this->payments->apply($tender, $amount, $figure['owed']);
        if ($shaped['minor'] > $figure['owed']) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $lines = $this->posting->tenders($shaped['minor'], $shaped['tender']);
        $this->ownsTransaction = !$this->pdo->inTransaction();
        if ($this->ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $journalId = $this->posting->postReceipt($figure['vendor_id'], $figure['booth_id'], $today, $sessionId, $shaped['minor'], $shaped['tender'], $lines);
            if ($this->ownsTransaction) {
                $this->pdo->commit();
            }
            $this->ownsTransaction = false;

            return $journalId;
        } catch (DatabaseRefusal $error) {
            $this->rollbackOwned();
            throw $error;
        }
    }

    /**
     * @return array{
     *     journal_id: string,
     *     number: int,
     *     posted_on: string,
     *     vendor: string,
     *     booth: string,
     *     amount: int,
     *     cash: int,
     *     check_number: string,
     *     tenders: list<array{name: string, amount: int}>
     * }|null
     */
    public function read(string $journalId): ?array
    {
        return $this->slips->read($journalId);
    }

    public function change(string $tendered, int $cash): ?int
    {
        return $this->slips->change($tendered, $cash);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth_id: string, booth: string, owes: int, collect: string}>
     */
    public function debtors(string $today): array
    {
        return $this->payouts->debtors($today);
    }

    /**
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string}|null
     */
    private function packFigure(string $vendorId, string $boothId, string $today): ?array
    {
        if ($vendorId === '') {
            return null;
        }
        $quote = $this->payouts->quote($vendorId, $today);
        if ($quote === null || $quote['owes'] < 1) {
            return null;
        }
        $picked = $this->boothOf($vendorId, $boothId, $today);
        if ($picked === null) {
            return null;
        }

        $parts = $this->payments->parts($quote['owes'], $this->lateCharged($vendorId, ''));

        return [
            'vendor_id' => $vendorId,
            'vendor_name' => $picked['name'],
            'booth_id' => $picked['booth_id'],
            'booth' => $picked['booth'],
            'owed' => $quote['owes'],
            'rent' => $parts['rent'],
            'late' => $parts['late'],
            'collect' => $this->dollars($quote['owes']),
        ];
    }

    /**
     * @return array{name: string, booth_id: string, booth: string}|null
     */
    private function boothOf(string $vendorId, string $boothId, string $today): ?array
    {
        foreach ($this->payouts->debtors($today) as $row) {
            if ($row['vendor_id'] !== $vendorId) {
                continue;
            }
            if ($boothId !== '' && $row['booth_id'] !== $boothId) {
                continue;
            }
            $key = $row['booth_id'] !== '' ? $row['booth_id'] : $boothId;

            return [
                'name' => $row['name'],
                'booth_id' => $key,
                'booth' => $row['booth'],
            ];
        }

        return null;
    }

    /**
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string}|null
     */
    private function openFigure(string $vendorId, string $boothId, string $today): ?array
    {
        if ($boothId === '') {
            return null;
        }
        $rows = $this->statements->bound(
            "SELECT vendor.id::text AS vendor_id, booth.code,
                    btrim(vendor.first_name || ' ' || vendor.last_name) AS vendor_name,
                    open.open_minor
             FROM booths AS booth
             JOIN booth_assignments AS assignment
               ON assignment.booth_id = booth.id
              AND assignment.starts_on <= ?::date
              AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             JOIN (
                 SELECT line.subledger_ref AS booth_id, sum(line.debit_minor - line.credit_minor) AS open_minor
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'booth_rent_receivable'
                   AND line.subledger_type = 'booth'
                 GROUP BY line.subledger_ref
                 HAVING sum(line.debit_minor - line.credit_minor) > 0
             ) AS open ON open.booth_id::uuid = booth.id
             WHERE booth.id = ?::uuid
             ORDER BY assignment.starts_on DESC
             LIMIT 1",
            [$today, $today, $boothId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $found = RowValue::string($row['vendor_id'] ?? null);
        if ($vendorId !== '' && $vendorId !== $found) {
            return null;
        }
        $owed = RowValue::integer($row['open_minor'] ?? null);
        if ($owed < 1) {
            return null;
        }

        $parts = $this->payments->parts($owed, $this->lateCharged($found, $boothId));

        return [
            'vendor_id' => $found,
            'vendor_name' => RowValue::string($row['vendor_name'] ?? null),
            'booth_id' => $boothId,
            'booth' => RowValue::string($row['code'] ?? null),
            'owed' => $owed,
            'rent' => $parts['rent'],
            'late' => $parts['late'],
            'collect' => $this->dollars($owed),
        ];
    }

    /**
     * @return list<array{label: string, date: string, amount: int, journal_id: string}>
     */
    public function explain(string $vendorId, string $boothId, int $owed): array
    {
        if ($vendorId === '' || $owed < 1) {
            return [];
        }
        $booth = $this->settings->rentPrepaid() ? '' : $boothId;
        if ($booth === '' && !$this->settings->rentPrepaid()) {
            return [];
        }

        return (new RentBill())->explain($this->moves($vendorId, $booth), $owed);
    }

    /**
     * @return list<array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int}>
     */
    private function moves(string $vendorId, string $boothId): array
    {
        $sql = "SELECT journal.id::text AS journal_id, journal.source_type AS source,
                       to_char(journal.posting_date, 'YYYY-MM-DD') AS posted_on,
                       COALESCE(booth.code, '') AS booth,
                       line.debit_minor, line.credit_minor
                FROM journal_lines AS line
                JOIN journals AS journal ON journal.id = line.journal_id
                JOIN chart_roles AS role ON role.account_id = line.account_id
                LEFT JOIN booths AS booth ON booth.id::text = line.subledger_ref
                WHERE role.role = 'booth_rent_receivable'
                  AND line.subledger_type = 'booth'
                  AND (line.debit_minor > 0 OR line.credit_minor > 0)
                  AND journal.is_reversal = false
                  AND NOT EXISTS (
                      SELECT 1 FROM journals AS reversal
                      WHERE reversal.reverses_journal_id = journal.id
                  )
                  AND EXISTS (
                      SELECT 1 FROM booth_assignments AS assignment
                      WHERE assignment.vendor_id = ?::uuid
                        AND assignment.booth_id = line.subledger_ref::uuid
                        AND assignment.assignment_range @> journal.posting_date
                  )";
        $params = [$vendorId];
        if ($boothId !== '') {
            $sql .= ' AND line.subledger_ref = ?';
            $params[] = $boothId;
        }
        $sql .= ' ORDER BY journal.posting_date, journal.journal_no, line.line_no';
        $moves = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $moves[] = [
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'source' => RowValue::string($row['source'] ?? null),
                'date' => RowValue::string($row['posted_on'] ?? null),
                'booth' => RowValue::string($row['booth'] ?? null),
                'debit' => RowValue::integer($row['debit_minor'] ?? null),
                'credit' => RowValue::integer($row['credit_minor'] ?? null),
            ];
        }

        return $moves;
    }

    private function lateCharged(string $vendorId, string $boothId): int
    {
        $sql = "SELECT COALESCE(sum(line.debit_minor), 0) AS late_minor
                FROM journal_lines AS line
                JOIN journals AS journal ON journal.id = line.journal_id
                JOIN chart_roles AS role ON role.account_id = line.account_id
                WHERE role.role = 'booth_rent_receivable'
                  AND journal.source_type = 'late_fee'
                  AND line.subledger_type = 'booth'
                  AND line.debit_minor > 0
                  AND NOT EXISTS (
                      SELECT 1 FROM journals AS reversal
                      WHERE reversal.reverses_journal_id = journal.id
                  )
                  AND EXISTS (
                      SELECT 1 FROM booth_assignments AS assignment
                      WHERE assignment.vendor_id = ?::uuid
                        AND assignment.booth_id = line.subledger_ref::uuid
                        AND assignment.assignment_range @> journal.posting_date
                  )";
        $params = [$vendorId];
        if ($boothId !== '') {
            $sql .= ' AND line.subledger_ref = ?';
            $params[] = $boothId;
        }
        $row = $this->statements->bound($sql, $params)[0] ?? null;

        return is_array($row) ? RowValue::integer($row['late_minor'] ?? null) : 0;
    }

    private function dollars(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function rollbackOwned(): void
    {
        if (!$this->ownsTransaction || !$this->pdo->inTransaction()) {
            $this->ownsTransaction = false;

            return;
        }
        $this->pdo->rollBack();
        $this->ownsTransaction = false;
    }
}
