<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The vendor register and the rent payments list, as the database builds
 * them. Every amount, balance and total here is the database's; this class
 * only reads it.
 *
 * @phpstan-type RegisterRow array{kind: string, date: string, journal_id: string, number: string, what: string, booth: string, debit: ?int, credit: ?int, balance: int}
 * @phpstan-type PaymentRow array{date: string, journal_id: string, number: string, vendor_id: string, vendor: string, code: string, booth: string, months: string, paid_by: string, how: string, register: string, cashier: string, run_id: string, run_date: string, voided: bool, amount: int}
 * @phpstan-type PaymentTotal array{kind: string, label: string, count: int, amount: int}
 */
final class VendorMoney
{
    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(private readonly Statements $statements)
    {
    }

    public static function isId(string $value): bool
    {
        return preg_match(self::UUID, $value) === 1;
    }

    /**
     * @return list<RegisterRow>
     */
    public function register(string $vendorId, string $from, string $to, string $boothId): array
    {
        $rows = [];
        foreach (
            $this->statements->bound(
                "SELECT row_kind, to_char(posted_on, 'YYYY-MM-DD') AS posted_on, journal_id::text AS journal_id,
                        journal_no::text AS journal_no, what, booth_code, debit_minor, credit_minor, balance_minor
                 FROM stall_vendor_register(?::uuid, ?::date, ?::date, NULLIF(?, '')::uuid)",
                [$vendorId, $from, $to, $boothId],
            ) as $row
        ) {
            $rows[] = [
                'kind' => RowValue::string($row['row_kind'] ?? null),
                'date' => RowValue::string($row['posted_on'] ?? null),
                'journal_id' => RowValue::optionalString($row['journal_id'] ?? null) ?? '',
                'number' => RowValue::optionalString($row['journal_no'] ?? null) ?? '',
                'what' => RowValue::string($row['what'] ?? null),
                'booth' => RowValue::optionalString($row['booth_code'] ?? null) ?? '',
                'debit' => RowValue::optionalInteger($row['debit_minor'] ?? null),
                'credit' => RowValue::optionalInteger($row['credit_minor'] ?? null),
                'balance' => RowValue::integer($row['balance_minor'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param array{vendor: string, booth: string, paid_by: string, q: string} $filters
     * @return list<PaymentRow>
     */
    public function payments(string $from, string $to, array $filters): array
    {
        $rows = [];
        foreach (
            $this->statements->bound(
                "SELECT to_char(posted_on, 'YYYY-MM-DD') AS posted_on, journal_id::text AS journal_id,
                        journal_no::text AS journal_no, vendor_id::text AS vendor_id, vendor_code, vendor_name,
                        booth_code, months, paid_by, how, register_name, cashier, run_id::text AS run_id,
                        to_char(run_date, 'YYYY-MM-DD') AS run_date, voided, amount_minor
                 FROM stall_rent_payments(?::date, ?::date, NULLIF(?, '')::uuid, NULLIF(?, '')::uuid, NULLIF(?, ''), NULLIF(?, ''))",
                [$from, $to, $filters['vendor'], $filters['booth'], $filters['paid_by'], $filters['q']],
            ) as $row
        ) {
            $rows[] = [
                'date' => RowValue::string($row['posted_on'] ?? null),
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'number' => RowValue::string($row['journal_no'] ?? null),
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'vendor' => RowValue::string($row['vendor_name'] ?? null),
                'code' => RowValue::string($row['vendor_code'] ?? null),
                'booth' => RowValue::string($row['booth_code'] ?? null),
                'months' => RowValue::optionalString($row['months'] ?? null) ?? '',
                'paid_by' => RowValue::string($row['paid_by'] ?? null),
                'how' => RowValue::string($row['how'] ?? null),
                'register' => RowValue::optionalString($row['register_name'] ?? null) ?? '',
                'cashier' => RowValue::optionalString($row['cashier'] ?? null) ?? '',
                'run_id' => RowValue::optionalString($row['run_id'] ?? null) ?? '',
                'run_date' => RowValue::optionalString($row['run_date'] ?? null) ?? '',
                'voided' => PgBool::read($row['voided'] ?? null),
                'amount' => RowValue::integer($row['amount_minor'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param array{vendor: string, booth: string, paid_by: string, q: string} $filters
     * @return list<PaymentTotal>
     */
    public function paymentTotals(string $from, string $to, array $filters): array
    {
        $rows = [];
        foreach (
            $this->statements->bound(
                "SELECT total_kind, label, payments, amount_minor
                 FROM stall_rent_payment_totals(?::date, ?::date, NULLIF(?, '')::uuid, NULLIF(?, '')::uuid, NULLIF(?, ''), NULLIF(?, ''))",
                [$from, $to, $filters['vendor'], $filters['booth'], $filters['paid_by'], $filters['q']],
            ) as $row
        ) {
            $rows[] = [
                'kind' => RowValue::string($row['total_kind'] ?? null),
                'label' => RowValue::string($row['label'] ?? null),
                'count' => RowValue::integer($row['payments'] ?? null),
                'amount' => RowValue::integer($row['amount_minor'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * Unclaimed money by vendor and age, for the yearly state filing.
     *
     * @return list<array{vendor_id: string, vendor: string, code: string, moved_on: string, moved: int, paid: int, remitted: int, held: int, days: int, references: string}>
     */
    public function unclaimed(string $today): array
    {
        $listed = [];
        foreach (
            $this->statements->bound(
                "SELECT vendor_id::text AS vendor_id, vendor, code, moved_on::text AS moved_on, moved_minor, paid_minor,
                        remitted_minor, held_minor, days_held, COALESCE(remitted_refs, '') AS refs
                 FROM stall_unclaimed_balances(?::date)",
                [$today]
            ) as $row
        ) {
            $listed[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'vendor' => RowValue::string($row['vendor'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'moved_on' => RowValue::string($row['moved_on'] ?? null),
                'moved' => RowValue::integer($row['moved_minor'] ?? null),
                'paid' => RowValue::integer($row['paid_minor'] ?? null),
                'remitted' => RowValue::integer($row['remitted_minor'] ?? null),
                'held' => RowValue::integer($row['held_minor'] ?? null),
                'days' => RowValue::integer($row['days_held'] ?? null),
                'references' => RowValue::string($row['refs'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * D9: every write-off in the range, with who, why, and what was taken back since.
     *
     * @return list<array{journal_id: string, date: string, vendor_id: string, vendor: string, booth: string, kind: string, amount: int, recovered: int, reason: string, who: string}>
     */
    public function badDebts(string $from, string $to): array
    {
        $listed = [];
        foreach (
            $this->statements->bound(
                "SELECT journal_id::text AS journal_id, posting_date::text AS posting_date, vendor_id::text AS vendor_id, vendor,
                        COALESCE(booth, '') AS booth, kind, amount_minor, recovered_minor, reason, who
                 FROM stall_bad_debts(?::date, ?::date)",
                [$from, $to]
            ) as $row
        ) {
            $listed[] = [
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'date' => RowValue::string($row['posting_date'] ?? null),
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'vendor' => RowValue::string($row['vendor'] ?? null),
                'booth' => RowValue::string($row['booth'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
                'amount' => RowValue::integer($row['amount_minor'] ?? null),
                'recovered' => RowValue::integer($row['recovered_minor'] ?? null),
                'reason' => RowValue::string($row['reason'] ?? null),
                'who' => RowValue::string($row['who'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * Every vendor, those who have left marked so, for the vendor choice.
     *
     * @return list<array{id: string, label: string}>
     */
    public function vendors(): array
    {
        $choices = [];
        foreach ($this->statements->rows('SELECT id::text AS id, code, name, active FROM vendors ORDER BY name, code') as $row) {
            $left = !PgBool::read($row['active'] ?? null);
            $choices[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'label' => RowValue::string($row['name'] ?? null) . ' (' . RowValue::string($row['code'] ?? null) . ')'
                    . ($left ? ' (has left the store)' : ''),
            ];
        }

        return $choices;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function booths(): array
    {
        $choices = [];
        foreach ($this->statements->rows('SELECT id::text AS id, code FROM booths ORDER BY code') as $row) {
            $choices[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'label' => 'Booth ' . RowValue::string($row['code'] ?? null),
            ];
        }

        return $choices;
    }
}
