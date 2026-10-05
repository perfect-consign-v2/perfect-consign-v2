<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
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
    ) {
    }

    /**
     * The amount the vendor page already shows. Pack owes when sales pay the rent,
     * otherwise that booth's open receivable.
     *
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, collect: string}|null
     */
    public function figure(string $vendorId, string $boothId, string $today): ?array
    {
        if ($this->settings->rentPrepaid()) {
            return $this->packFigure($vendorId, $boothId, $today);
        }

        return $this->openFigure($vendorId, $boothId, $today);
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string} $tender
     */
    public function collect(
        string $vendorId,
        string $boothId,
        string $today,
        string $sessionId,
        string $amount,
        array $tender,
    ): string {
        $parsed = Money::parseMinor($amount);
        $figure = $this->figure($vendorId, $boothId, $today);
        if ($figure === null || $parsed > $figure['owed']) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $lines = $this->posting->tenders($parsed, $tender);
        $this->ownsTransaction = !$this->pdo->inTransaction();
        if ($this->ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $journalId = $this->posting->postReceipt($figure['vendor_id'], $figure['booth_id'], $today, $sessionId, $parsed, $tender, $lines);
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
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, collect: string}|null
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

        return [
            'vendor_id' => $vendorId,
            'vendor_name' => $picked['name'],
            'booth_id' => $picked['booth_id'],
            'booth' => $picked['booth'],
            'owed' => $quote['owes'],
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
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, collect: string}|null
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

        return [
            'vendor_id' => $found,
            'vendor_name' => RowValue::string($row['vendor_name'] ?? null),
            'booth_id' => $boothId,
            'booth' => RowValue::string($row['code'] ?? null),
            'owed' => $owed,
            'collect' => $this->dollars($owed),
        ];
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
