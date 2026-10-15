<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The rent lines a receipt explains: posted charges and rent the pack has not posted yet.
 */
final class RentMoves
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallPayoutSettings $settings,
    ) {
    }

    /**
     * @return list<array{label: string, date: string, amount: int, journal_id: string}>
     */
    public function explain(string $vendorId, string $boothId, int $owed, string $today): array
    {
        if ($vendorId === '' || $owed < 1) {
            return [];
        }
        $booth = $this->settings->rentPrepaid() ? '' : $boothId;
        if ($booth === '' && !$this->settings->rentPrepaid()) {
            return [];
        }
        $moves = $this->moves($vendorId, $booth);
        foreach ($this->uncovered($vendorId, $booth, $today) as $move) {
            $moves[] = $move;
        }

        return (new RentBill())->explain($moves, $owed);
    }

    /**
     * @return list<array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int}>
     */
    private function moves(string $vendorId, string $boothId): array
    {
        $sql = "SELECT journal.id::text AS journal_id, journal.source_type AS source,
                       to_char(journal.posting_date, 'YYYY-MM-DD') AS posted_on,
                       COALESCE(booth.code, '') AS booth,
                       line.debit_minor, line.credit_minor,
                       (
                           SELECT to_char(assignment.starts_on, 'YYYY-MM-DD')
                           FROM booth_assignments AS assignment
                           WHERE assignment.vendor_id = ?::uuid
                             AND assignment.booth_id = line.subledger_ref::uuid
                             AND assignment.assignment_range @> journal.posting_date
                           ORDER BY assignment.starts_on DESC
                           LIMIT 1
                       ) AS starts_on
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
        $params = [$vendorId, $vendorId];
        if ($boothId !== '') {
            $sql .= ' AND line.subledger_ref = ?';
            $params[] = $boothId;
        }
        $sql .= ' ORDER BY journal.posting_date, journal.journal_no, line.line_no';
        $moves = [];
        $cycleDay = $this->settings->cycleDay();
        foreach ($this->statements->bound($sql, $params) as $row) {
            $source = RowValue::string($row['source'] ?? null);
            $postedOn = RowValue::string($row['posted_on'] ?? null);
            $startsOn = RowValue::optionalString($row['starts_on'] ?? null);
            $moves[] = [
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'source' => $source,
                'date' => $this->covered($source, $postedOn, $startsOn, $cycleDay),
                'booth' => RowValue::string($row['booth'] ?? null),
                'debit' => RowValue::integer($row['debit_minor'] ?? null),
                'credit' => RowValue::integer($row['credit_minor'] ?? null),
            ];
        }

        return $moves;
    }

    /**
     * @return list<array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int}>
     */
    private function uncovered(string $vendorId, string $boothId, string $today): array
    {
        if (!$this->settings->rentPrepaid() || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return [];
        }
        $sql = "SELECT booth.code AS booth,
                       to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                       rate.amount_minor,
                       to_char(series.period_start::date, 'YYYY-MM-DD') AS period_start
                FROM booth_assignments AS assignment
                JOIN assignment_rent AS rate ON rate.assignment_id = assignment.id
                JOIN booths AS booth ON booth.id = assignment.booth_id
                JOIN LATERAL generate_series(
                    stall_rent_period_start(assignment.starts_on)::timestamp,
                    stall_rent_period_start(?::date)::timestamp,
                    interval '1 month'
                ) AS series(period_start)
                  ON assignment.starts_on <= stall_rent_period_end(series.period_start::date)
                 AND (assignment.ends_on IS NULL OR assignment.ends_on >= series.period_start::date)
                WHERE assignment.vendor_id = ?::uuid
                  AND NOT EXISTS (
                      SELECT 1
                      FROM journals AS posted
                      WHERE posted.source_type = 'rent_charge'
                        AND posted.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || stall_rent_charge_suffix(series.period_start::date)
                        AND NOT posted.is_reversal
                        AND NOT EXISTS (
                            SELECT 1 FROM journals AS reversal
                            WHERE reversal.reverses_journal_id = posted.id
                        )
                  )";
        $params = [$today, $vendorId];
        if ($boothId !== '') {
            $sql .= ' AND assignment.booth_id = ?::uuid';
            $params[] = $boothId;
        }
        $sql .= ' ORDER BY series.period_start, booth.code';
        $cycle = new RentCycle($this->settings->cycleDay());
        $moves = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $move = $this->unposted($row, $cycle);
            if ($move !== null) {
                $moves[] = $move;
            }
        }

        return $moves;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int}|null
     */
    private function unposted(array $row, RentCycle $cycle): ?array
    {
        $startsOn = RowValue::string($row['starts_on'] ?? null);
        $period = RowValue::string($row['period_start'] ?? null);
        $posting = $startsOn > $period ? $startsOn : $period;
        if ($startsOn > $cycle->end($period)) {
            return null;
        }
        $amount = $cycle->amount(RowValue::integer($row['amount_minor'] ?? null), null, $startsOn, $posting);
        if ($amount < 1) {
            return null;
        }
        $partial = RentCover::partial($cycle->day(), $posting, $startsOn);

        return [
            'journal_id' => '',
            'source' => 'rent_charge',
            'date' => $partial !== '' ? $partial : $period,
            'booth' => RowValue::string($row['booth'] ?? null),
            'debit' => $amount,
            'credit' => 0,
        ];
    }

    private function covered(string $source, string $postedOn, ?string $startsOn, int $cycleDay): string
    {
        if ($source !== 'rent_charge' || $startsOn === null) {
            return $postedOn;
        }
        $partial = RentCover::partial($cycleDay, $postedOn, $startsOn);

        return $partial !== '' ? $partial : $postedOn;
    }
}
