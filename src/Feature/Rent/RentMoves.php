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
    public function explain(string $vendorId, int $owed, string $today): array
    {
        if ($vendorId === '' || $owed < 1) {
            return [];
        }
        $moves = $this->moves($vendorId);
        foreach ($this->uncovered($vendorId, $today) as $move) {
            $moves[] = $move;
        }

        return (new RentBill())->explain($moves, $owed);
    }

    /**
     * Posted rent and late fees for every booth this vendor has had.
     *
     * @return list<array{journal_id: string, source: string, date: string, booth: string, booth_id: string, stay_id: string, debit: int, credit: int}>
     */
    public function charges(string $vendorId): array
    {
        if ($vendorId === '') {
            return [];
        }

        return $this->moves($vendorId);
    }

    /**
     * @return list<array{journal_id: string, source: string, date: string, booth: string, booth_id: string, stay_id: string, debit: int, credit: int}>
     */
    private function moves(string $vendorId): array
    {
        $sql = "SELECT journal.id::text AS journal_id, journal.source_type AS source,
                       to_char(journal.posting_date, 'YYYY-MM-DD') AS posted_on,
                       booth.code AS booth,
                       stay.booth_id::text AS booth_id,
                       stay.id::text AS stay_id,
                       line.debit_minor, line.credit_minor,
                       to_char(stay.starts_on, 'YYYY-MM-DD') AS starts_on
                FROM journal_lines AS line
                JOIN journals AS journal ON journal.id = line.journal_id
                JOIN chart_roles AS role ON role.account_id = line.account_id
                JOIN booth_assignments AS stay ON stay.id::text = line.subledger_ref
                JOIN booths AS booth ON booth.id = stay.booth_id
                WHERE role.role = 'booth_rent_receivable'
                  AND line.subledger_type = 'assignment'
                  AND (line.debit_minor > 0 OR line.credit_minor > 0)
                  AND journal.is_reversal = false
                  AND NOT EXISTS (
                      SELECT 1 FROM journals AS reversal
                      WHERE reversal.reverses_journal_id = journal.id
                  )
                  AND stay.vendor_id = ?::uuid";
        $params = [$vendorId];
        $sql .= ' ORDER BY journal.posting_date, journal.journal_no, line.line_no';
        $moves = [];
        $cycleDay = $this->settings->getCycleDay();
        foreach ($this->statements->bound($sql, $params) as $row) {
            $source = RowValue::string($row['source'] ?? null);
            $postedOn = RowValue::string($row['posted_on'] ?? null);
            $startsOn = RowValue::optionalString($row['starts_on'] ?? null);
            $moves[] = [
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'source' => $source,
                'date' => $this->covered($source, $postedOn, $startsOn, $cycleDay),
                'booth' => RowValue::string($row['booth'] ?? null),
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'stay_id' => RowValue::string($row['stay_id'] ?? null),
                'debit' => RowValue::integer($row['debit_minor'] ?? null),
                'credit' => RowValue::integer($row['credit_minor'] ?? null),
            ];
        }

        return $moves;
    }

    /**
     * @return list<array{journal_id: string, source: string, date: string, booth: string, booth_id: string, stay_id: string, debit: int, credit: int}>
     */
    private function uncovered(string $vendorId, string $today): array
    {
        if (!$this->settings->isRentPrepaid() || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return [];
        }
        $sql = "SELECT book.booth_code AS booth, book.booth_id::text AS booth_id,
                       book.assignment_id::text AS stay_id,
                       to_char(book.starts_on, 'YYYY-MM-DD') AS starts_on,
                       to_char(book.period_start, 'YYYY-MM-DD') AS period_start,
                       book.quote_minor
                FROM stall_rent_book(
                    COALESCE((SELECT min(starts_on) FROM booth_assignments WHERE vendor_id = ?::uuid), ?::date),
                    ?::date
                ) AS book
                WHERE book.vendor_id = ?::uuid
                  AND book.chargeable
                ORDER BY book.period_start, book.booth_code";
        $cycleDay = $this->settings->getCycleDay();
        $moves = [];
        foreach ($this->statements->bound($sql, [$vendorId, $today, $today, $vendorId]) as $row) {
            $startsOn = RowValue::string($row['starts_on'] ?? null);
            $period = RowValue::string($row['period_start'] ?? null);
            $partial = RentCover::partial($cycleDay, $startsOn > $period ? $startsOn : $period, $startsOn);
            $moves[] = [
                'journal_id' => '',
                'source' => 'rent_charge',
                'date' => $partial !== '' ? $partial : $period,
                'booth' => RowValue::string($row['booth'] ?? null),
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'stay_id' => RowValue::string($row['stay_id'] ?? null),
                'debit' => RowValue::integer($row['quote_minor'] ?? null),
                'credit' => 0,
            ];
        }

        return $moves;
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
