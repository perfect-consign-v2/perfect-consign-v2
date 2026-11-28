<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The rent book and rent charges. The database builds each row (one per stay in
 * each rent period, and one per empty booth with an asking rate), computes the
 * amount, and dates and posts the charge. This class only reads and asks.
 *
 * @phpstan-import-type RentRow from RentBook
 */
final class RentStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallSettings $mall,
        private readonly PostingFunctions $postings,
    ) {
    }

    public function today(): string
    {
        return $this->mall->today();
    }

    /**
     * Every stay and rent period that touches the calendar month.
     *
     * @return list<RentRow>
     */
    public function book(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
        $first = $month . '-01';
        $last = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');

        return $this->rows($first, $last);
    }

    /**
     * Charges one stay for one rent period. Charging it again changes nothing.
     */
    public function chargeStay(string $assignmentId, string $periodStart): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $assignmentId) !== 1) {
            throw new DatabaseRefusal('Choose a booth to charge.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) !== 1) {
            throw new DatabaseRefusal('Choose the month to charge.');
        }
        $dated = $this->statements->bound(
            "SELECT to_char(stall_rent_charge_date(?::uuid, ?::date), 'YYYY-MM-DD') AS on_date",
            [$assignmentId, $periodStart]
        );
        $onDate = RowValue::optionalString($dated[0]['on_date'] ?? null);
        if ($onDate === null) {
            throw new DatabaseRefusal('Choose a booth to charge.');
        }

        return $this->postings->post(
            $onDate,
            'stall_charge_rent(?::uuid, ?::date)',
            [$assignmentId, $periodStart],
        );
    }

    /**
     * Months other than the one on screen with a stay that can still be charged.
     *
     * @return list<array{month: string, label: string, count: int}>
     */
    public function unchargedMonths(string $today, string $exceptMonth): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new InvalidArgumentException('a rent day is YYYY-MM-DD');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $exceptMonth) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
        $listed = [];
        foreach (
            $this->statements->bound(
                "SELECT to_char(book.period_start, 'YYYY-MM') AS month,
                        to_char(min(book.period_start), 'YYYY-MM-DD') AS first_period,
                        count(*) AS stays
                 FROM stall_rent_book(
                     COALESCE((SELECT min(starts_on) FROM booth_assignments), ?::date),
                     ?::date
                 ) AS book
                 WHERE book.chargeable
                   AND to_char(book.period_start, 'YYYY-MM') <> ?
                 GROUP BY month
                 ORDER BY month",
                [$today, $today, $exceptMonth]
            ) as $row
        ) {
            $listed[] = [
                'month' => RowValue::string($row['month'] ?? null),
                'label' => $this->periodLabel(RowValue::string($row['first_period'] ?? null)),
                'count' => RowValue::integer($row['stays'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @return list<RentRow>
     */
    private function rows(string $from, string $to): array
    {
        $rows = [];
        foreach (
            $this->statements->bound(
                "SELECT book.assignment_id::text AS assignment_id,
                        book.booth_id::text AS booth_id,
                        book.booth_code,
                        book.booth_name,
                        book.vendor_id::text AS vendor_id,
                        book.vendor_name,
                        to_char(book.period_start, 'YYYY-MM-DD') AS period_start,
                        book.quote_minor,
                        book.charge_journal::text AS charge_journal,
                        book.charge_minor,
                        book.chargeable,
                        to_char(book.charge_date, 'YYYY-MM-DD') AS charge_date,
                        book.open_minor
                 FROM stall_rent_book(?::date, ?::date) AS book
                 ORDER BY book.booth_code, book.period_start, book.starts_on NULLS FIRST",
                [$from, $to]
            ) as $row
        ) {
            $rows[] = $this->row($row);
        }
        usort(
            $rows,
            static function (array $left, array $right): int {
                $booth = strnatcasecmp($left['code'], $right['code']);

                return $booth !== 0 ? $booth : strcmp($left['period_start'], $right['period_start']);
            }
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return RentRow
     */
    private function row(array $row): array
    {
        $stay = RowValue::optionalString($row['assignment_id'] ?? null);
        $booth = RowValue::string($row['booth_id'] ?? null);
        $journal = RowValue::optionalString($row['charge_journal'] ?? null);
        $open = RowValue::integer($row['open_minor'] ?? null);
        $period = RowValue::string($row['period_start'] ?? null);

        return [
            'id' => $stay !== null ? $stay . ':' . $period : $booth,
            'assignment_id' => $stay,
            'booth_id' => $booth,
            'code' => RowValue::string($row['booth_code'] ?? null),
            'name' => RowValue::string($row['booth_name'] ?? null),
            'vendor_id' => RowValue::optionalString($row['vendor_id'] ?? null),
            'vendor_name' => RowValue::optionalString($row['vendor_name'] ?? null),
            'period_start' => $period,
            'period_label' => $this->periodLabel($period),
            'rate_minor' => RowValue::optionalInteger($row['quote_minor'] ?? null),
            'charged' => $journal !== null,
            'chargeable' => in_array($row['chargeable'] ?? false, [true, 1, '1', 't', 'true'], true),
            'charge_minor' => RowValue::optionalInteger($row['charge_minor'] ?? null),
            'journal_id' => $journal,
            'open_minor' => $open,
            'charge_date' => RowValue::optionalString($row['charge_date'] ?? null) ?? '',
            'collect_amount' => $open > 0
                ? intdiv($open, 100) . '.' . str_pad((string) ($open % 100), 2, '0', STR_PAD_LEFT)
                : '',
        ];
    }

    /**
     * A rent period in words: the month when it starts on the 1st, otherwise its first and last day.
     */
    private function periodLabel(string $periodStart): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) !== 1) {
            return '';
        }
        $start = new DateTimeImmutable($periodStart);
        if ($start->format('j') === '1') {
            return $start->format('F Y');
        }
        $end = $start->modify('+1 month')->modify('-1 day');

        return $start->format('M j') . ' to ' . $end->format('M j, Y');
    }
}
