<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One month of rent: who holds the booth, the stored rate, the live charge, and what is still owed.
 */
final class RentBook
{
    public function __construct(private readonly RentLines $lines)
    {
    }

    /**
     * @param array{
     *   booths: list<array<string, mixed>>,
     *   assignments: list<array<string, mixed>>,
     *   rates: list<array<string, mixed>>,
     *   charges: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>
     * } $facts
     * @return array{
     *   month: string,
     *   label: string,
     *   previous: string,
     *   next: string,
     *   today: string,
     *   stats: array{due_minor: int, due_count: int, charged_minor: int, charged_count: int, owed_minor: int, owed_count: int},
     *   rows: list<array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     vendor_id: ?string,
     *     vendor_name: ?string,
     *     rate_minor: ?int,
     *     charged: bool,
     *     chargeable: bool,
     *     charge_minor: ?int,
     *     journal_id: ?string,
     *     open_minor: int,
     *     charge_date: string,
     *     collect_amount: string
     *   }>
     * }
     */
    public function assemble(array $facts, string $month, string $today, int $cycleDay = 1): array
    {
        $this->requireMonth($month);
        $this->requireDay($today);
        $rows = $this->lines->build($facts, $month, $today, $cycleDay);
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
     * @param list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rate_minor: ?int,
     *   charged: bool,
     *   chargeable: bool,
     *   charge_minor: ?int,
     *   journal_id: ?string,
     *   open_minor: int,
     *   charge_date: string,
     *   collect_amount: string
     * }> $rows
     * @return array{
     *   rows: list<array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     vendor_id: ?string,
     *     vendor_name: ?string,
     *     rate_minor: ?int,
     *     charged: bool,
     *     chargeable: bool,
     *     charge_minor: ?int,
     *     journal_id: ?string,
     *     open_minor: int,
     *     charge_date: string,
     *     collect_amount: string
     *   }>,
     *   counts: array{due: int, charged: int, owed: int},
     *   standing: string,
     *   matched: int
     * }
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
     * @param list<array{rate_minor: ?int, charged: bool, chargeable: bool, charge_minor: ?int, open_minor: int}> $rows
     * @return array{due_minor: int, due_count: int, charged_minor: int, charged_count: int, owed_minor: int, owed_count: int}
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
        foreach ($rows as $row) {
            if ($this->due($row)) {
                $stats['due_minor'] += $row['rate_minor'] ?? 0;
                $stats['due_count']++;
            }
            if ($row['charged'] === true) {
                $stats['charged_minor'] += $row['charge_minor'] ?? 0;
                $stats['charged_count']++;
            }
            if ($row['open_minor'] > 0) {
                $stats['owed_minor'] += $row['open_minor'];
                $stats['owed_count']++;
            }
        }

        return $stats;
    }

    /**
     * @param list<array{rate_minor: ?int, charged: bool, chargeable: bool, open_minor: int}> $rows
     * @return array{due: int, charged: int, owed: int}
     */
    private function counts(array $rows): array
    {
        $counts = ['due' => 0, 'charged' => 0, 'owed' => 0];
        foreach ($rows as $row) {
            if ($this->due($row)) {
                $counts['due']++;
            }
            if ($row['charged'] === true) {
                $counts['charged']++;
            }
            if ($row['open_minor'] > 0) {
                $counts['owed']++;
            }
        }

        return $counts;
    }

    /**
     * @param array{rate_minor: ?int, charged: bool, chargeable: bool} $row
     */
    private function due(array $row): bool
    {
        return $row['chargeable'] && $row['charged'] !== true;
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
     * @param list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rate_minor: ?int,
     *   charged: bool,
     *   chargeable: bool,
     *   charge_minor: ?int,
     *   journal_id: ?string,
     *   open_minor: int,
     *   charge_date: string,
     *   collect_amount: string
     * }> $rows
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rate_minor: ?int,
     *   charged: bool,
     *   chargeable: bool,
     *   charge_minor: ?int,
     *   journal_id: ?string,
     *   open_minor: int,
     *   charge_date: string,
     *   collect_amount: string
     * }>
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
     * @param array{rate_minor: ?int, charged: bool, chargeable: bool, open_minor: int} $row
     */
    private function kept(array $row, string $standing): bool
    {
        if ($standing === 'due') {
            return $this->due($row);
        }
        if ($standing === 'charged') {
            return $row['charged'] === true;
        }

        return $row['open_minor'] > 0;
    }

    /**
     * @param array{code: string, vendor_name: ?string} $row
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
