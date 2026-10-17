<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PDOException;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutRunStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly OccupiedPack $occupied,
    ) {
    }

    /**
     * @param list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}> $pack
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}>
     */
    public function addBooths(array $pack, string $today): array
    {
        $filled = $this->occupied->fill($pack, $today);
        usort(
            $filled,
            static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code'])
        );

        return $filled;
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function pack(string $runId): array
    {
        return $this->lines(
            'SELECT vendor_id, code, name, booth, booth_id::text AS booth_id, mode, prior, charged, paid, payable, rent,
                    rent_minor AS month_rent,
                    "check" AS check_amount, owes AS owe_amount, settled_minor, adjustment_minor
             FROM payout_pack WHERE run_id = ?::uuid AND mode = ?
             ORDER BY code',
            [$runId, 'pay']
        );
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function stored(string $runId): array
    {
        return $this->lines(
            "SELECT line.vendor_id, vendor.code,
                    btrim(vendor.first_name || ' ' || vendor.last_name) AS name,
                    '' AS booth,
                    (
                        SELECT booth.id::text
                        FROM booth_assignments AS assignment
                        JOIN booths AS booth ON booth.id = assignment.booth_id
                        JOIN payout_runs AS run ON run.id = line.run_id
                        WHERE assignment.vendor_id = line.vendor_id
                          AND assignment.assignment_range @> run.posting_date
                        ORDER BY booth.code
                        LIMIT 1
                    ) AS booth_id,
                    line.mode,
                    line.rent_brought_forward_minor AS prior,
                    line.rent_charged_minor + line.rent_minor AS charged,
                    line.rent_paid_since_minor + line.settlement_applied_minor AS paid,
                    line.carried_payable_minor AS payable,
                    line.carried_rent_minor AS rent,
                    line.rent_minor AS month_rent,
                    line.check_minor AS check_amount,
                    line.owes_minor AS owe_amount,
                    line.settled_minor,
                    line.adjustment_minor
             FROM payout_run_lines AS line
             JOIN vendors AS vendor ON vendor.id = line.vendor_id
             WHERE line.run_id = ?::uuid
             ORDER BY vendor.code",
            [$runId]
        );
    }

    /**
     * @return list<array{code: string, name: string, mode: string}>
     */
    public function excluded(): array
    {
        $rows = $this->statements->rows(
            "SELECT code, first_name, last_name, payout_mode
             FROM vendors
             WHERE active AND payout_mode IN ('credit_only', 'hold')
             ORDER BY code"
        );
        $excluded = [];
        foreach ($rows as $row) {
            $excluded[] = [
                'code' => RowValue::string($row['code'] ?? null),
                'name' => trim(RowValue::string($row['first_name'] ?? null) . ' ' . RowValue::string($row['last_name'] ?? null)),
                'mode' => RowValue::string($row['payout_mode'] ?? null),
            ];
        }

        return $excluded;
    }

    public function checksVoided(string $runId): bool
    {
        $rows = $this->statements->bound(
            'SELECT ' . $this->voidedChecks('?::uuid') . ' AS check_void',
            [$runId]
        );

        return RowValue::string($rows[0]['check_void'] ?? '') === 'voided';
    }

    private function voidedChecks(string $runIdSql): string
    {
        return "CASE WHEN (
            SELECT count(*) FILTER (WHERE NOT journal.is_reversal) > 0
               AND count(*) FILTER (
                   WHERE NOT journal.is_reversal
                     AND NOT EXISTS (
                         SELECT 1 FROM journals AS reversal
                         WHERE reversal.reverses_journal_id = journal.id
                     )
               ) = 0
            FROM payout_run_journals AS link
            JOIN journals AS journal ON journal.id = link.journal_id
            WHERE link.run_id = {$runIdSql} AND link.role = 'disbursement'
        ) THEN 'voided' ELSE '' END";
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        return $this->statements->rows(
            'SELECT run.id, run.run_no, run.kind, run.sales_from, run.sales_to, run.rent_month, run.tender, run.status,
                    run.reversed_at, reversal.reversed_by AS undone_by, reversal.reversal_reason AS undone_reason,
                    COALESCE(one.vendor_name, \'\') AS vendor_name,
                    one.check_minor, ' . $this->voidedChecks('run.id') . ' AS check_void
             FROM payout_runs AS run
             LEFT JOIN LATERAL (
                 SELECT reversed_by, reversal_reason
                 FROM payout_runs
                 WHERE reverses_run_id = run.id
                 ORDER BY run_no DESC
                 LIMIT 1
             ) AS reversal ON true
             LEFT JOIN LATERAL (
                 SELECT btrim(vendor.first_name || \' \' || vendor.last_name) AS vendor_name,
                        CASE
                            WHEN line.check_minor <> 0 THEN line.check_minor
                            ELSE COALESCE((
                                SELECT journal.debit_total_minor
                                FROM payout_run_journals AS link
                                JOIN journals AS journal ON journal.id = link.journal_id
                                WHERE link.run_id = run.id
                                  AND link.role = \'disbursement\'
                                  AND NOT journal.is_reversal
                                LIMIT 1
                            ), line.check_minor)
                        END AS check_minor
                 FROM payout_run_lines AS line
                 JOIN vendors AS vendor ON vendor.id = line.vendor_id
                 WHERE line.run_id = run.id AND run.kind = \'single\'
                 ORDER BY vendor.code
                 LIMIT 1
             ) AS one ON true
             WHERE run.reverses_run_id IS NULL
             ORDER BY run.run_no DESC'
        );
    }

    public function openDraft(string $salesFrom, string $salesTo, string $rentMonth): ?string
    {
        $rows = $this->statements->bound(
            "SELECT id FROM payout_runs
             WHERE status = 'draft' AND kind = 'batch' AND reverses_run_id IS NULL
               AND sales_from = ?::date AND sales_to = ?::date AND rent_month = ?::date
             ORDER BY created_at DESC LIMIT 1",
            [$salesFrom, $salesTo, $rentMonth]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function preview(string $salesFrom, string $salesTo, string $rentMonth, string $postingDate): array
    {
        return $this->lines(
            'SELECT vendor_id, code, name, booth, booth_id::text AS booth_id, mode, prior, charged, paid, payable, rent,
                    rent_minor AS month_rent,
                    "check" AS check_amount, owes AS owe_amount, settled_minor, adjustment_minor
             FROM stall_payout_pack(NULL::uuid, ?::date, ?::date, ?::date, ?::date)
             WHERE mode = ?
             ORDER BY code',
            [$salesFrom, $salesTo, $rentMonth, $postingDate, 'pay']
        );
    }

    public function ensureDraft(
        string $salesFrom,
        string $salesTo,
        string $rentMonth,
        string $postingDate,
        string $tender = 'check',
    ): string {
        $tender = $this->tender($tender);
        $existing = $this->openDraft($salesFrom, $salesTo, $rentMonth);
        if ($existing !== null) {
            $this->stamp($existing, $postingDate, $tender);

            return $existing;
        }
        try {
            return $this->begin($salesFrom, $salesTo, $rentMonth, $postingDate, $tender);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            $winner = $this->openDraft($salesFrom, $salesTo, $rentMonth);
            if ($winner === null) {
                throw $error;
            }
            $this->stamp($winner, $postingDate, $tender);

            return $winner;
        }
    }

    public function review(string $runId): void
    {
        $this->statements->change(
            "UPDATE payout_runs
             SET reviewed_checksum = stall_pack_checksum(id), reviewed_at = clock_timestamp()
             WHERE id = ?::uuid AND status = 'draft'",
            [$runId]
        );
    }

    /**
     * @return array{tender: string, status: string, posting_date: string, rent_month: string}
     */
    public function run(string $runId): array
    {
        $rows = $this->statements->bound(
            "SELECT tender, status,
                    COALESCE(to_char(posting_date, 'YYYY-MM-DD'), '') AS posting_date,
                    COALESCE(to_char(rent_month, 'YYYY-MM-DD'), '') AS rent_month
             FROM payout_runs WHERE id = ?::uuid",
            [$runId]
        );
        if ($rows === []) {
            return [
                'tender' => '',
                'status' => '',
                'posting_date' => '',
                'rent_month' => '',
            ];
        }

        return [
            'tender' => RowValue::string($rows[0]['tender'] ?? null),
            'status' => RowValue::string($rows[0]['status'] ?? null),
            'posting_date' => RowValue::string($rows[0]['posting_date'] ?? null),
            'rent_month' => RowValue::string($rows[0]['rent_month'] ?? null),
        ];
    }

    private function tender(string $tender): string
    {
        if ($tender === 'drawer_cash' || $tender === 'safe_cash' || $tender === 'check') {
            return $tender;
        }

        return 'check';
    }

    private function stamp(string $runId, string $postingDate, string $tender): void
    {
        $this->statements->change(
            "UPDATE payout_runs
             SET posting_date = ?::date, tender = ?
             WHERE id = ?::uuid AND status = 'draft'",
            [$postingDate, $tender, $runId]
        );
    }

    private function begin(
        string $salesFrom,
        string $salesTo,
        string $rentMonth,
        string $postingDate,
        string $tender,
    ): string {
        $rows = $this->statements->bound(
            "INSERT INTO payout_runs (
                book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
             )
             SELECT id, 'batch', 'monthly', ?::date, ?::date, ?::date, ?, 'draft', ?::date
             FROM ledger_books
             WHERE is_default
             RETURNING id",
            [$salesFrom, $salesTo, $rentMonth, $tender, $postingDate]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @param list<mixed> $params
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    private function lines(string $sql, array $params): array
    {
        $pack = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $pack[] = [
                'vendor_id' => $this->text($row, 'vendor_id'),
                'code' => $this->text($row, 'code'),
                'name' => $this->text($row, 'name'),
                'booth' => $this->text($row, 'booth'),
                'boothId' => $this->optionalText($row, 'booth_id'),
                'mode' => $this->text($row, 'mode'),
                'prior' => RowValue::integer($row['prior'] ?? null),
                'charged' => RowValue::integer($row['charged'] ?? null),
                'paid' => RowValue::integer($row['paid'] ?? null),
                'payable' => RowValue::integer($row['payable'] ?? null),
                'rent' => RowValue::integer($row['rent'] ?? null),
                'monthRent' => RowValue::integer($row['month_rent'] ?? null),
                'check' => RowValue::integer($row['check_amount'] ?? null),
                'owes' => RowValue::integer($row['owe_amount'] ?? null),
                'settled' => RowValue::integer($row['settled_minor'] ?? null),
                'adjustment' => RowValue::integer($row['adjustment_minor'] ?? null),
            ];
        }

        return $pack;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException($key . ' ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function optionalText(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return '';
        }

        return $this->text($row, $key);
    }
}
