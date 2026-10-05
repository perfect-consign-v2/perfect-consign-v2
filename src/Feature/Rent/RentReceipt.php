<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RentReceipt
{
    private bool $ownsTransaction = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
        private readonly PayoutStore $payouts,
        private readonly PayoutSettler $settler,
        private readonly MallPayoutSettings $settings,
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
        $lines = $this->tenders($parsed, $tender);
        $this->ownsTransaction = !$this->pdo->inTransaction();
        if ($this->ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $journalId = $this->postReceipt($figure['vendor_id'], $figure['booth_id'], $today, $sessionId, $parsed, $tender, $lines);
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
        $rows = $this->statements->bound(
            "SELECT journal_no, to_char(posting_date, 'YYYY-MM-DD') AS posted_on, debit_total_minor
             FROM journals
             WHERE id = ?::uuid AND source_type = 'rent_payment' AND is_reversal = false",
            [$journalId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $party = $this->party($journalId);
        $tenders = $this->tenderLines($journalId);

        return [
            'journal_id' => $journalId,
            'number' => RowValue::integer($row['journal_no'] ?? null),
            'posted_on' => RowValue::string($row['posted_on'] ?? null),
            'vendor' => $party['vendor'],
            'booth' => $party['booth'],
            'amount' => RowValue::integer($row['debit_total_minor'] ?? null),
            'cash' => $tenders['cash'],
            'check_number' => $this->checkOn($journalId),
            'tenders' => $tenders['lines'],
        ];
    }

    public function change(string $tendered, int $cash): ?int
    {
        $cleaned = str_replace(['$', ',', ' '], '', trim($tendered));
        if ($cleaned === '' || $cash < 1 || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $cleaned) !== 1) {
            return null;
        }
        $handed = $this->minor($tendered);
        if ($handed < $cash) {
            return null;
        }

        return $handed - $cash;
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
        $name = '';
        $booth = '';
        $boothKey = $boothId;
        foreach ($this->payouts->debtors($today) as $row) {
            if ($row['vendor_id'] !== $vendorId) {
                continue;
            }
            $name = $row['name'];
            if ($boothId === '' || $row['booth_id'] === $boothId) {
                $booth = $row['booth'];
                $boothKey = $row['booth_id'] !== '' ? $row['booth_id'] : $boothKey;
                break;
            }
        }
        if ($name === '') {
            return null;
        }

        return [
            'vendor_id' => $vendorId,
            'vendor_name' => $name,
            'booth_id' => $boothKey,
            'booth' => $booth,
            'owed' => $quote['owes'],
            'collect' => $this->dollars($quote['owes']),
        ];
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

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string} $tender
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $debits
     */
    private function postReceipt(
        string $vendorId,
        string $boothId,
        string $today,
        string $sessionId,
        int $amount,
        array $tender,
        array $debits,
    ): string {
        if ($this->settings->rentPrepaid()) {
            $quote = $this->payouts->quote($vendorId, $today);
            if ($quote === null) {
                throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
            }
            $this->settler->chargeMissing($quote['run_id'], $vendorId);
        }
        $id = $this->ledger->newId();
        $check = $this->minor($tender['checks']) > 0 ? trim($tender['check']) : null;
        $lines = $debits;
        foreach ($this->credits($vendorId, $boothId, $amount) as $credit) {
            $lines[] = $credit;
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'rent-payment:' . $id,
            postingDate: $today,
            source: new JournalSource('rent_payment', $id, 'Rent receipt'),
            lines: $lines,
            sessionId: $sessionId,
            checkNumber: $check,
        ));
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function credits(string $vendorId, string $boothId, int $amount): array
    {
        $left = $amount;
        $lines = [];
        $limited = !$this->settings->rentPrepaid();
        foreach ($this->payouts->boothRent($vendorId) as $booth) {
            if ($limited && $booth['booth_id'] !== $boothId) {
                continue;
            }
            $take = min($left, $booth['open_minor']);
            if ($take < 1) {
                continue;
            }
            $lines[] = PostedLines::entry(
                $this->ledger->roleAccount('booth_rent_receivable'),
                0,
                $take,
                'booth',
                $booth['booth_id']
            );
            $left -= $take;
        }
        if ($left !== 0 || $lines === []) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }

        return $lines;
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string} $tender
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function tenders(int $amount, array $tender): array
    {
        $cash = $this->minor($tender['cash']);
        $card = $this->minor($tender['card']);
        $checks = $this->minor($tender['checks']);
        if ($cash + $card + $checks !== $amount) {
            throw new DatabaseRefusal('a rent receipt needs a tender');
        }
        $this->requireCheck($checks, trim($tender['check']));
        $this->handed($cash, $tender['tendered']);

        return $this->debitLines($cash, $card, $checks);
    }

    private function requireCheck(int $checks, string $number): void
    {
        if ($checks > 0 && ($number === '' || strlen($number) > 40)) {
            throw new DatabaseRefusal('a rent check needs a check number');
        }
        if ($checks < 1 && $number !== '') {
            throw new DatabaseRefusal('a cash or card rent receipt has no check number');
        }
    }

    private function handed(int $cash, string $raw): void
    {
        if ($cash < 1) {
            return;
        }
        $given = trim($raw) === '' ? $cash : $this->minor($raw);
        if ($given < $cash) {
            throw new DatabaseRefusal('the cash tendered does not cover the cash amount');
        }
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function debitLines(int $cash, int $card, int $checks): array
    {
        $lines = [];
        foreach (['cash' => $cash, 'card' => $card, 'checks' => $checks] as $role => $amount) {
            if ($amount < 1) {
                continue;
            }
            $lines[] = PostedLines::entry($this->ledger->roleAccount($role), $amount, 0, null, null);
        }
        if ($lines === []) {
            throw new DatabaseRefusal('a rent receipt needs a tender');
        }

        return $lines;
    }

    private function minor(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0' || $raw === '0.0' || $raw === '0.00') {
            return 0;
        }

        return Money::parseMinor($raw);
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

    /**
     * @return array{vendor: string, booth: string}
     */
    private function party(string $journalId): array
    {
        $vendors = [];
        $booths = [];
        foreach ($this->statements->bound(
            "SELECT booth.code AS booth,
                    COALESCE(btrim(vendor.first_name || ' ' || vendor.last_name), '') AS vendor_name
             FROM journal_lines AS line
             JOIN journals AS posted ON posted.id = line.journal_id
             JOIN booths AS booth ON booth.id = line.subledger_ref::uuid
             LEFT JOIN booth_assignments AS assignment
               ON assignment.booth_id = booth.id
              AND assignment.assignment_range @> posted.posting_date
             LEFT JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             WHERE line.journal_id = ?::uuid
               AND line.subledger_type = 'booth'
               AND line.credit_minor > 0
             ORDER BY booth.code",
            [$journalId]
        ) as $row) {
            $booth = RowValue::string($row['booth'] ?? null);
            $vendor = RowValue::string($row['vendor_name'] ?? null);
            if ($booth !== '' && !in_array($booth, $booths, true)) {
                $booths[] = $booth;
            }
            if ($vendor !== '' && !in_array($vendor, $vendors, true)) {
                $vendors[] = $vendor;
            }
        }

        return [
            'vendor' => implode(', ', $vendors),
            'booth' => implode(', ', $booths),
        ];
    }

    /**
     * @return array{cash: int, lines: list<array{name: string, amount: int}>}
     */
    private function tenderLines(string $journalId): array
    {
        $cash = 0;
        $lines = [];
        foreach ($this->statements->bound(
            "SELECT role.role, line.debit_minor
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE line.journal_id = ?::uuid AND line.debit_minor > 0
             ORDER BY line.line_no",
            [$journalId]
        ) as $row) {
            $role = RowValue::string($row['role'] ?? null);
            $amount = RowValue::integer($row['debit_minor'] ?? null);
            if ($role === 'cash') {
                $cash += $amount;
            }
            $lines[] = ['name' => $this->tenderName($role), 'amount' => $amount];
        }

        return ['cash' => $cash, 'lines' => $lines];
    }

    private function tenderName(string $role): string
    {
        if ($role === 'cash') {
            return 'Cash';
        }
        if ($role === 'checks') {
            return 'Check';
        }
        if ($role === 'card') {
            return 'Card';
        }

        return $role;
    }

    private function checkOn(string $journalId): string
    {
        $rows = $this->statements->bound(
            'SELECT check_number FROM register_checks WHERE journal_id = ?::uuid',
            [$journalId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return '';
        }

        return RowValue::string($row['check_number'] ?? null);
    }
}
