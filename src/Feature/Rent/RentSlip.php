<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RentSlip
{
    public function __construct(private readonly Statements $statements)
    {
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

    private function minor(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0' || $raw === '0.0' || $raw === '0.00') {
            return 0;
        }

        return Money::parseMinor($raw);
    }
}
