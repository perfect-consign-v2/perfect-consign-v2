<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorCheck
{
    private const LABELS = [
        'sale' => 'Sale',
        'item_return' => 'Return',
        'rent_charge' => 'Rent',
        'rent_payment' => 'Rent you paid',
        'settlement' => 'Rent taken from your share',
        'check_disbursement' => 'Check',
        'cash_disbursement' => 'Cash',
        'safe_disbursement' => 'Paid from the safe',
        'store_purchase' => 'Store purchase',
        'late_fee' => 'Late fee',
        'booth_correction' => 'Booth correction',
    ];

    public function __construct(
        private readonly Statements $statements,
        private readonly PayoutStore $payouts,
        private readonly VendorAccount $account,
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     hold: bool,
     *     open: bool,
     *     payable: int,
     *     rent_label: string,
     *     rent: int,
     *     check: int,
     *     desk: int,
     *     booth: array{code: string, rent: int|null, note: string}|null,
     *     others: list<array{code: string, starts_on: string, ends_on: string, rent: int|null}>,
     *     activity: list<array{date: string, label: string, amount: int, run_id: string}>,
     *     last: array{run_id: string, paid_on: string, amount: int, number: string}|null
     * }
     */
    public function home(string $vendorId, string $today): array
    {
        $profile = $this->profile($vendorId);
        $quoted = $this->payouts->quote($vendorId, $today);
        $numbers = $this->account->numbers($vendorId);
        $hold = $profile['mode'] === 'credit_only' || $profile['mode'] === 'hold';
        $stays = $this->stays($vendorId, $today);
        $page = [
            'name' => $profile['name'],
            'hold' => $hold,
            'open' => $quoted !== null,
            'payable' => $numbers['payable'],
            'rent_label' => 'Rent you owe',
            'rent' => $numbers['rent'],
            'check' => 0,
            'desk' => $numbers['rent'] > 0 ? $numbers['rent'] : 0,
            'booth' => $stays['booth'],
            'others' => $stays['others'],
            'activity' => $this->activity($vendorId),
            'last' => $this->last($vendorId),
        ];
        if ($quoted === null) {
            return $page;
        }

        return $this->quoted($page, $quoted);
    }

    /**
     * @param array{
     *     name: string,
     *     hold: bool,
     *     open: bool,
     *     payable: int,
     *     rent_label: string,
     *     rent: int,
     *     check: int,
     *     desk: int,
     *     booth: array{code: string, rent: int|null, note: string}|null,
     *     others: list<array{code: string, starts_on: string, ends_on: string, rent: int|null}>,
     *     activity: list<array{date: string, label: string, amount: int, run_id: string}>,
     *     last: array{run_id: string, paid_on: string, amount: int, number: string}|null
     * } $page
     * @param array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool} $quoted
     * @return array{
     *     name: string,
     *     hold: bool,
     *     open: bool,
     *     payable: int,
     *     rent_label: string,
     *     rent: int,
     *     check: int,
     *     desk: int,
     *     booth: array{code: string, rent: int|null, note: string}|null,
     *     others: list<array{code: string, starts_on: string, ends_on: string, rent: int|null}>,
     *     activity: list<array{date: string, label: string, amount: int, run_id: string}>,
     *     last: array{run_id: string, paid_on: string, amount: int, number: string}|null
     * }
     */
    private function quoted(array $page, array $quoted): array
    {
        $page['payable'] = $quoted['payable'];
        $page['rent_label'] = $quoted['rent_from_sales'] ? 'Rent from your share' : 'Rent you owe';
        $page['rent'] = $this->rentFigure($quoted);
        $page['check'] = $quoted['check'];
        $page['desk'] = $quoted['rent_from_sales'] && $quoted['owes'] > 0 ? $quoted['owes'] : 0;

        return $page;
    }

    /**
     * @param array{rent: int, owes: int, settled: int, rent_from_sales: bool} $quoted
     */
    private function rentFigure(array $quoted): int
    {
        if ($quoted['rent_from_sales']) {
            return $quoted['settled'];
        }
        if ($quoted['owes'] > 0) {
            return $quoted['owes'];
        }

        return $quoted['rent'];
    }

    public function lastPaidOn(string $vendorId): ?string
    {
        $last = $this->last($vendorId);

        return $last === null ? null : $last['paid_on'];
    }

    /**
     * @return array{
     *     paid_on: string,
     *     amount: int,
     *     number: string,
     *     lines: list<array{date: string, label: string, amount: int, run_id: string}>
     * }|null
     */
    public function cleared(string $vendorId, string $runId): ?array
    {
        $rows = $this->statements->bound(
            "SELECT to_char(journal.posting_date, 'YYYY-MM-DD') AS paid_on,
                    line.check_minor, line.check_number
             FROM payout_run_lines AS line
             JOIN journals AS journal ON journal.id = line.disbursement_journal_id
             WHERE line.vendor_id = ?::uuid AND line.run_id = ?::uuid
               AND line.disbursement_journal_id IS NOT NULL",
            [$vendorId, $runId]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $number = $row['check_number'] ?? null;

        return [
            'paid_on' => RowValue::string($row['paid_on'] ?? null),
            'amount' => RowValue::integer($row['check_minor'] ?? null),
            'number' => is_string($number) ? $number : '',
            'lines' => $this->activity($vendorId, $runId),
        ];
    }

    /**
     * @return array{name: string, mode: string}
     */
    private function profile(string $vendorId): array
    {
        $rows = $this->statements->bound(
            'SELECT name, payout_mode FROM vendors WHERE id = ?::uuid',
            [$vendorId]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return ['name' => '', 'mode' => 'pay'];
        }

        return [
            'name' => RowValue::string($row['name'] ?? null),
            'mode' => RowValue::string($row['payout_mode'] ?? null),
        ];
    }

    /**
     * @return array{
     *     booth: array{code: string, rent: int|null, note: string}|null,
     *     others: list<array{code: string, starts_on: string, ends_on: string, rent: int|null}>
     * }
     */
    private function stays(string $vendorId, string $today): array
    {
        $booth = null;
        $others = [];
        foreach ($this->statements->bound(
            "SELECT booth.code,
                    to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                    to_char(assignment.ends_on, 'YYYY-MM-DD') AS ends_on,
                    rate.amount_minor
             FROM booth_assignments AS assignment
             JOIN booths AS booth ON booth.id = assignment.booth_id
             LEFT JOIN assignment_rent AS rate ON rate.assignment_id = assignment.id
             WHERE assignment.vendor_id = ?::uuid
             ORDER BY assignment.starts_on DESC",
            [$vendorId]
        ) as $row) {
            $starts = RowValue::string($row['starts_on'] ?? null);
            $ends = RowValue::optionalString($row['ends_on'] ?? null);
            $rent = $this->rent($row['amount_minor'] ?? null);
            $current = $starts <= $today && ($ends === null || $ends >= $today);
            if ($current && $booth === null) {
                $booth = [
                    'code' => RowValue::string($row['code'] ?? null),
                    'rent' => $rent,
                    'note' => $this->note($today, $starts, $ends),
                ];
                continue;
            }
            $others[] = [
                'code' => RowValue::string($row['code'] ?? null),
                'starts_on' => $starts,
                'ends_on' => $ends ?? '',
                'rent' => $rent,
            ];
        }

        return ['booth' => $booth, 'others' => $others];
    }

    /**
     * @return list<array{date: string, label: string, amount: int, run_id: string}>
     */
    private function activity(string $vendorId, ?string $runId = null): array
    {
        $sql = "SELECT to_char(statement.posting_date, 'YYYY-MM-DD') AS posted_on,
                       statement.source_type, statement.role, statement.debit_minor, statement.credit_minor,
                       journal.is_reversal,
                       (
                           SELECT line.run_id::text
                           FROM payout_run_lines AS line
                           WHERE line.disbursement_journal_id = journal.id
                             AND line.vendor_id = statement.vendor_id
                           LIMIT 1
                       ) AS run_id
                FROM vendor_statement AS statement
                JOIN journals AS journal ON journal.id = statement.journal_id
                WHERE statement.vendor_id = ?::uuid
                  AND NOT (statement.source_type = 'settlement' AND statement.role = 'booth_rent_receivable')";
        $params = [$vendorId];
        if ($runId !== null) {
            $sql .= " AND journal.id IN (
                        SELECT link.journal_id FROM payout_run_journals AS link WHERE link.run_id = ?::uuid
                        UNION
                        SELECT paid.disbursement_journal_id
                        FROM payout_run_lines AS paid
                        WHERE paid.run_id = ?::uuid AND paid.vendor_id = ?::uuid
                          AND paid.disbursement_journal_id IS NOT NULL
                        UNION
                        SELECT settled.settlement_journal_id
                        FROM payout_run_lines AS settled
                        WHERE settled.run_id = ?::uuid AND settled.vendor_id = ?::uuid
                          AND settled.settlement_journal_id IS NOT NULL
                    )";
            $params = [$vendorId, $runId, $runId, $vendorId, $runId, $vendorId];
        }
        $sql .= ' ORDER BY statement.posting_date DESC, statement.occurred_at DESC, statement.journal_no DESC, statement.line_no DESC';
        $lines = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $role = RowValue::string($row['role'] ?? null);
            $debit = RowValue::integer($row['debit_minor'] ?? null);
            $credit = RowValue::integer($row['credit_minor'] ?? null);
            $run = RowValue::optionalString($row['run_id'] ?? null);
            $lines[] = [
                'date' => RowValue::string($row['posted_on'] ?? null),
                'label' => $this->label(
                    $this->reversed($row['is_reversal'] ?? null) ? 'reversal' : RowValue::string($row['source_type'] ?? null),
                    $role,
                    $debit
                ),
                'amount' => $role === 'booth_rent_receivable' ? $debit - $credit : $credit - $debit,
                'run_id' => $run ?? '',
            ];
        }

        return $lines;
    }

    /**
     * @return array{run_id: string, paid_on: string, amount: int, number: string}|null
     */
    private function last(string $vendorId): ?array
    {
        $rows = $this->statements->bound(
            "SELECT line.run_id::text AS run_id,
                    to_char(journal.posting_date, 'YYYY-MM-DD') AS paid_on,
                    line.check_minor, line.check_number
             FROM payout_run_lines AS line
             JOIN journals AS journal ON journal.id = line.disbursement_journal_id
             WHERE line.vendor_id = ?::uuid AND line.disbursement_journal_id IS NOT NULL
             ORDER BY journal.posting_date DESC, line.run_id DESC
             LIMIT 1",
            [$vendorId]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $number = $row['check_number'] ?? null;

        return [
            'run_id' => RowValue::string($row['run_id'] ?? null),
            'paid_on' => RowValue::string($row['paid_on'] ?? null),
            'amount' => RowValue::integer($row['check_minor'] ?? null),
            'number' => is_string($number) ? $number : '',
        ];
    }

    private function note(string $today, string $startsOn, ?string $endsOn): string
    {
        $month = substr($today, 0, 7);
        if ($endsOn !== null && str_starts_with($endsOn, $month)) {
            return 'The month you leave is a full month of rent.';
        }
        $day = (int) substr($startsOn, 8, 2);
        if (str_starts_with($startsOn, $month) && $day > 1) {
            return "This month's rent is the days left.";
        }

        return '';
    }

    private function rent(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return RowValue::integer($amount);
    }

    private function reversed(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function label(string $source, string $role, int $debit): string
    {
        if ($source === 'reversal') {
            return 'Undone';
        }
        if ($source === 'sale' && $role === 'vendor_payable' && $debit > 0) {
            return 'Bought at the store';
        }

        return self::LABELS[$source] ?? 'Entry';
    }
}
