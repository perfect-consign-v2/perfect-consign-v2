<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One month of rent on screen. The rows come from the database rent book: one
 * per stay in each rent period, so a vendor who leaves mid-month keeps her own
 * row and charge beside the vendor who moves in.
 *
 * @phpstan-type RentRow array{
 *   id: string,
 *   assignment_id: ?string,
 *   booth_id: string,
 *   code: string,
 *   name: string,
 *   vendor_id: ?string,
 *   vendor_name: ?string,
 *   period_start: string,
 *   period_label: string,
 *   rate_minor: ?int,
 *   charged: bool,
 *   chargeable: bool,
 *   charge_minor: ?int,
 *   journal_id: ?string,
 *   open_minor: int,
 *   charge_date: string,
 *   collect_amount: string
 * }
 * @phpstan-type RentStats array{due_minor: int, due_count: int, charged_minor: int, charged_count: int, owed_minor: int, owed_count: int}
 */
final class RentBook
{
    /**
     * @param list<RentRow> $rows
     * @return array{
     *   month: string,
     *   label: string,
     *   previous: string,
     *   next: string,
     *   today: string,
     *   stats: RentStats,
     *   rows: list<RentRow>
     * }
     */
    public function assemble(array $rows, string $month, string $today): array
    {
        $this->requireMonth($month);
        $this->requireDay($today);
        $start = new DateTimeImmutable($month . '-01');

        return [
            'month' => $month,
            'label' => $start->format('F Y'),
            'previous' => $start->modify('-1 month')->format('Y-m'),
            'next' => $start->modify('+1 month')->format('Y-m'),
            'today' => $today,
            'stats' => $this->stats($rows),
            'rows' => $rows,
        ];
    }

    public function month(string $requested, string $today): string
    {
        $this->requireDay($today);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) !== 1) {
            return substr($today, 0, 7);
        }

        return $requested;
    }

    /**
     * @param list<RentRow> $rows
     * @return array{rows: list<RentRow>, counts: array{due: int, charged: int, owed: int}, standing: string, matched: int}
     */
    public function narrow(array $rows, string $query, string $standing): array
    {
        $needle = strtolower($query);
        $searched = [];
        foreach ($rows as $row) {
            if ($this->matches($row, $needle)) {
                $searched[] = $row;
            }
        }
        $counts = $this->counts($searched);
        $standing = $this->standing($standing, $counts);

        return [
            'rows' => $this->picked($searched, $standing),
            'counts' => $counts,
            'standing' => $standing,
            'matched' => count($searched),
        ];
    }

    /**
     * Still owed is per stay: a stay that spans two periods on this page counts once.
     *
     * @param list<RentRow> $rows
     * @return RentStats
     */
    private function stats(array $rows): array
    {
        $stats = [
            'due_minor' => 0,
            'due_count' => 0,
            'charged_minor' => 0,
            'charged_count' => 0,
            'owed_minor' => 0,
            'owed_count' => 0,
        ];
        $owing = [];
        foreach ($rows as $row) {
            if ($this->due($row)) {
                $stats['due_minor'] += $row['rate_minor'] ?? 0;
                $stats['due_count']++;
            }
            if ($row['charged']) {
                $stats['charged_minor'] += $row['charge_minor'] ?? 0;
                $stats['charged_count']++;
            }
            if ($row['open_minor'] > 0) {
                $owing[$row['assignment_id'] ?? $row['booth_id']] = $row['open_minor'];
            }
        }
        $stats['owed_minor'] = array_sum($owing);
        $stats['owed_count'] = count($owing);

        return $stats;
    }

    /**
     * @param list<RentRow> $rows
     * @return array{due: int, charged: int, owed: int}
     */
    private function counts(array $rows): array
    {
        $counts = ['due' => 0, 'charged' => 0, 'owed' => 0];
        foreach ($rows as $row) {
            if ($this->due($row)) {
                $counts['due']++;
            }
            if ($row['charged']) {
                $counts['charged']++;
            }
            if ($row['open_minor'] > 0) {
                $counts['owed']++;
            }
        }

        return $counts;
    }

    /**
     * @param RentRow $row
     */
    private function due(array $row): bool
    {
        return $row['chargeable'] && !$row['charged'];
    }

    /**
     * @param array{due: int, charged: int, owed: int} $counts
     */
    private function standing(string $value, array $counts): string
    {
        if ($value !== 'due' && $value !== 'charged' && $value !== 'owed') {
            return '';
        }
        if ($counts[$value] < 1) {
            return '';
        }

        return $value;
    }

    /**
     * @param list<RentRow> $rows
     * @return list<RentRow>
     */
    private function picked(array $rows, string $standing): array
    {
        if ($standing === '') {
            return $rows;
        }
        $visible = [];
        foreach ($rows as $row) {
            if ($this->kept($row, $standing)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * @param RentRow $row
     */
    private function kept(array $row, string $standing): bool
    {
        if ($standing === 'due') {
            return $this->due($row);
        }
        if ($standing === 'charged') {
            return $row['charged'];
        }

        return $row['open_minor'] > 0;
    }

    /**
     * @param RentRow $row
     */
    private function matches(array $row, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        if (str_contains(strtolower($row['code']), $needle)) {
            return true;
        }
        $name = $row['vendor_name'];
        if ($name === null) {
            return false;
        }

        return str_contains(strtolower($name), $needle);
    }

    private function requireMonth(string $month): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
    }

    private function requireDay(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new InvalidArgumentException('a rent date is YYYY-MM-DD');
        }
    }
}
