<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The rent history card on the vendor page: each month, newest first, what
 * was charged on each booth, how it was paid, and what is still owed. The
 * database decides which payment paid which charge.
 *
 * @phpstan-type RentPaid array{number: string, date: string, amount: int, how: string, register: string, cashier: string, run_id: string, run_date: string, journal_id: string, paid_by: string}
 * @phpstan-type RentCharge array{booth: string, kind: string, what: string, date: string, journal_id: string, charged: int, waived: bool, paid: int, owed: int, payments: list<RentPaid>}
 * @phpstan-type RentMonth array{month: string, charges: list<RentCharge>}
 */
final class RentHistory
{
    /** Months shown before Show all. */
    public const RECENT = 6;

    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{months: list<RentMonth>, more: bool}
     */
    public function forVendor(string $vendorId, bool $all): array
    {
        $months = [];
        foreach (
            $this->statements->bound(
                "SELECT to_char(period_start, 'YYYY-MM-DD') AS period_start, booth_code, kind, what,
                        to_char(posted_on, 'YYYY-MM-DD') AS posted_on, journal_id::text AS journal_id,
                        charged_minor, waived, paid_minor, owed_minor, payments::text AS payments
                 FROM stall_vendor_rent_history(?::uuid)",
                [$vendorId],
            ) as $row
        ) {
            $month = RowValue::string($row['period_start'] ?? null);
            $months[$month] ??= ['month' => $month, 'charges' => []];
            $months[$month]['charges'][] = [
                'booth' => RowValue::string($row['booth_code'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
                'what' => RowValue::string($row['what'] ?? null),
                'date' => RowValue::string($row['posted_on'] ?? null),
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'charged' => RowValue::integer($row['charged_minor'] ?? null),
                'waived' => PgBool::read($row['waived'] ?? null),
                'paid' => RowValue::integer($row['paid_minor'] ?? null),
                'owed' => RowValue::integer($row['owed_minor'] ?? null),
                'payments' => $this->payments(RowValue::string($row['payments'] ?? null)),
            ];
        }
        $listed = array_values($months);

        return [
            'months' => $all ? $listed : array_slice($listed, 0, self::RECENT),
            'more' => !$all && count($listed) > self::RECENT,
        ];
    }

    /**
     * @return list<RentPaid>
     */
    private function payments(string $json): array
    {
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $payments = [];
        foreach (is_array($decoded) ? $decoded : [] as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $payments[] = [
                'number' => (string) RowValue::integer($payment['journal_no'] ?? null),
                'date' => RowValue::string($payment['posted_on'] ?? null),
                'amount' => RowValue::integer($payment['amount_minor'] ?? null),
                'how' => RowValue::string($payment['how'] ?? null),
                'register' => RowValue::optionalString($payment['register'] ?? null) ?? '',
                'cashier' => RowValue::optionalString($payment['cashier'] ?? null) ?? '',
                'run_id' => RowValue::optionalString($payment['run_id'] ?? null) ?? '',
                'run_date' => RowValue::optionalString($payment['run_date'] ?? null) ?? '',
                'journal_id' => RowValue::string($payment['journal_id'] ?? null),
                'paid_by' => RowValue::string($payment['paid_by'] ?? null),
            ];
        }

        return $payments;
    }
}
