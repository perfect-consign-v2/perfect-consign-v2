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
        private readonly LateFee $fees,
    ) {
    }

    /**
     * Everything this vendor still owes, on every booth. Pack owes when sales pay the rent.
     *
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string}|null
     */
    public function figure(string $vendorId, string $boothId, string $today): ?array
    {
        if ($this->settings->rentPrepaid()) {
            return $this->packFigure($vendorId, $today);
        }

        return $this->openFigure($vendorId, $boothId, $today);
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     * @param list<string> $waived
     */
    public function collect(
        string $vendorId,
        string $boothId,
        string $today,
        string $sessionId,
        string $amount,
        array $tender,
        array $waived = [],
        string $note = '',
    ): string {
        $figure = $this->figure($vendorId, $boothId, $today);
        if ($figure === null) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $this->ownsTransaction = !$this->pdo->inTransaction();
        if ($this->ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->clearFees($figure, $today, $waived, $note);
            $journalId = '';
            if ($waived === [] || !$this->unpaid($tender, $amount)) {
                $journalId = $this->take($figure['vendor_id'], $boothId, $today, $sessionId, $amount, $tender);
            }
            $this->commitOwned();

            return $journalId;
        } catch (\Throwable $error) {
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
    private function packFigure(string $vendorId, string $today): ?array
    {
        if ($vendorId === '') {
            return null;
        }
        $quote = $this->payouts->quote($vendorId, $today);
        if ($quote === null || $quote['owes'] < 1) {
            return null;
        }
        $picked = $this->boothOf($vendorId, $today);
        if ($picked === null) {
            return null;
        }
        $booth = $this->booths($vendorId, $today);
        $parts = $this->payments->parts($quote['owes'], $this->lateCharged($vendorId, ''));

        return [
            'vendor_id' => $vendorId,
            'vendor_name' => $picked['name'],
            'booth_id' => $picked['booth_id'],
            'booth' => $booth !== '' ? $booth : $picked['booth'],
            'owed' => $quote['owes'],
            'rent' => $parts['rent'],
            'late' => $parts['late'],
            'collect' => $this->dollars($quote['owes']),
        ];
    }

    /**
     * @return array{name: string, booth_id: string, booth: string}|null
     */
    private function boothOf(string $vendorId, string $today): ?array
    {
        foreach ($this->payouts->debtors($today) as $row) {
            if ($row['vendor_id'] !== $vendorId) {
                continue;
            }

            return [
                'name' => $row['name'],
                'booth_id' => $row['booth_id'],
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
        if ($vendorId === '') {
            $vendorId = $this->vendorOn($boothId, $today);
        }
        if ($vendorId === '') {
            return null;
        }
        $owed = $this->owedOn($vendorId);
        $spots = $this->openBooths($vendorId);
        $name = $this->vendorName($vendorId);
        if ($owed < 1 || $name === null || $spots === []) {
            return null;
        }
        $parts = $this->payments->parts($owed, $this->lateCharged($vendorId, ''));

        return [
            'vendor_id' => $vendorId,
            'vendor_name' => $name,
            'booth_id' => $this->picked($spots, $boothId),
            'booth' => $this->codes($spots),
            'owed' => $owed,
            'rent' => $parts['rent'],
            'late' => $parts['late'],
            'collect' => $this->dollars($owed),
        ];
    }

    /**
     * @return list<array{label: string, date: string, amount: int, journal_id: string}>
     */
    public function explain(string $vendorId, int $owed, string $today): array
    {
        return (new RentMoves($this->statements, $this->settings))->explain($vendorId, $owed, $today);
    }

    /**
     * @param array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string} $figure
     * @param list<string> $waived
     */
    private function clearFees(array $figure, string $today, array $waived, string $note): void
    {
        if ($waived === []) {
            return;
        }
        $open = [];
        foreach ($this->explain($figure['vendor_id'], $figure['owed'], $today) as $line) {
            if ($line['journal_id'] !== '') {
                $open[$line['journal_id']] = true;
            }
        }
        $chosen = [];
        foreach ($waived as $id) {
            if (!isset($open[$id])) {
                throw new DatabaseRefusal('Choose a late fee.');
            }
            $chosen[$id] = $id;
        }
        foreach ($chosen as $id) {
            $this->fees->waive($id, $today, $note);
        }
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     */
    private function unpaid(array $tender, string $amount): bool
    {
        foreach (['cash', 'card', 'checks', 'tendered'] as $key) {
            $value = trim($tender[$key] ?? '');
            if ($value !== '' && $value !== '0' && $value !== '0.0' && $value !== '0.00') {
                return false;
            }
        }
        $amount = trim($amount);

        return $amount === '' || $amount === '0' || $amount === '0.0' || $amount === '0.00';
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     */
    private function take(
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
        if ($sessionId === '') {
            throw new DatabaseRefusal('Open a register to collect');
        }
        $shaped = $this->payments->apply($tender, $amount, $figure['owed']);
        if ($shaped['minor'] < 1) {
            throw new DatabaseRefusal('a rent receipt needs a tender');
        }
        if ($shaped['minor'] > $figure['owed']) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $lines = $this->posting->tenders($shaped['minor'], $shaped['tender']);

        return $this->posting->postReceipt(
            $figure['vendor_id'],
            $today,
            $sessionId,
            $shaped['minor'],
            $shaped['tender'],
            $lines,
        );
    }

    private function commitOwned(): void
    {
        if (!$this->ownsTransaction) {
            return;
        }
        $this->pdo->commit();
        $this->ownsTransaction = false;
    }

    private function vendorOn(string $boothId, string $today): string
    {
        if ($boothId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return '';
        }
        $rows = $this->statements->bound(
            "SELECT vendor.id::text AS vendor_id
             FROM booth_assignments AS assignment
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             WHERE assignment.booth_id = ?::uuid
             ORDER BY (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date) DESC, assignment.starts_on DESC
             LIMIT 1",
            [$boothId, $today]
        );
        $row = $rows[0] ?? null;

        return is_array($row) ? RowValue::string($row['vendor_id'] ?? null) : '';
    }

    private function owedOn(string $vendorId): int
    {
        $rows = $this->statements->bound(
            "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS open_minor
             FROM journal_lines AS line
             JOIN journals AS journal ON journal.id = line.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
               AND EXISTS (
                   SELECT 1 FROM booth_assignments AS assignment
                   WHERE assignment.vendor_id = ?::uuid
                     AND assignment.booth_id = line.subledger_ref::uuid
                     AND assignment.assignment_range @> journal.posting_date
               )",
            [$vendorId]
        );
        $row = $rows[0] ?? null;

        return is_array($row) ? RowValue::integer($row['open_minor'] ?? null) : 0;
    }

    private function vendorName(string $vendorId): ?string
    {
        $rows = $this->statements->bound(
            "SELECT btrim(first_name || ' ' || last_name) AS vendor_name FROM vendors WHERE id = ?::uuid",
            [$vendorId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $name = RowValue::string($row['vendor_name'] ?? null);

        return $name === '' ? null : $name;
    }

    /**
     * @return list<array{id: string, code: string}>
     */
    private function openBooths(string $vendorId): array
    {
        $rows = $this->statements->bound(
            "SELECT booth.id::text AS id, booth.code
             FROM (
                 SELECT line.subledger_ref AS booth_id
                 FROM journal_lines AS line
                 JOIN journals AS journal ON journal.id = line.journal_id
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'booth_rent_receivable'
                   AND line.subledger_type = 'booth'
                   AND EXISTS (
                       SELECT 1 FROM booth_assignments AS assignment
                       WHERE assignment.vendor_id = ?::uuid
                         AND assignment.booth_id = line.subledger_ref::uuid
                         AND assignment.assignment_range @> journal.posting_date
                   )
                 GROUP BY line.subledger_ref
                 HAVING sum(line.debit_minor - line.credit_minor) > 0
             ) AS open
             JOIN booths AS booth ON booth.id::text = open.booth_id
             ORDER BY booth.code",
            [$vendorId]
        );
        $spots = [];
        foreach ($rows as $row) {
            $spots[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
            ];
        }

        return $spots;
    }

    /**
     * @param list<array{id: string, code: string}> $spots
     */
    private function picked(array $spots, string $boothId): string
    {
        foreach ($spots as $spot) {
            if ($spot['id'] === $boothId) {
                return $boothId;
            }
        }
        $first = $spots[0] ?? null;

        return $first === null ? '' : $first['id'];
    }

    /**
     * @param list<array{id: string, code: string}> $spots
     */
    private function codes(array $spots): string
    {
        $codes = [];
        foreach ($spots as $spot) {
            $codes[] = $spot['code'];
        }

        return implode(', ', $codes);
    }

    private function booths(string $vendorId, string $today): string
    {
        $open = $this->codes($this->openBooths($vendorId));
        if ($open !== '') {
            return $open;
        }

        return implode(', ', $this->assigned($vendorId, $today));
    }

    /**
     * @return list<string>
     */
    private function assigned(string $vendorId, string $today): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return [];
        }
        $rows = $this->statements->bound(
            "SELECT booth.code
             FROM booth_assignments AS assignment
             JOIN booths AS booth ON booth.id = assignment.booth_id
             WHERE assignment.vendor_id = ?::uuid
               AND assignment.starts_on <= ?::date
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
             ORDER BY booth.code",
            [$vendorId, $today, $today]
        );
        $codes = [];
        foreach ($rows as $row) {
            $codes[] = RowValue::string($row['code'] ?? null);
        }

        return $codes;
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
