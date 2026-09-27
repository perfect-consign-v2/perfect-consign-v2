<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;

/**
 * Vendors with the booth each one holds today.
 */
final class VendorRoster
{
    private const FILTERS = [
        'active' => true,
        'ending' => true,
        'incoming' => true,
        'open' => true,
    ];

    public function __construct(private readonly VendorPlacement $placement)
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $booths
     * @return array{
     *   stats: array{vendors: int, active: int, ending: int, open_booths: int},
     *   open: list<array{id: string, code: string, name: string}>,
     *   vendors: list<array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     status: string,
     *     status_label: string,
     *     status_badge: string,
     *     since: string,
     *     booths: list<array{id: string, code: string, occupancy: string}>,
     *     current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *     upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *     past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     *   }>
     * }
     */
    public function assemble(array $rows, array $booths, string $today): array
    {
        $this->requireDate($today);
        $vendors = [];
        $active = 0;
        $ending = 0;
        $occupied = [];
        foreach ($this->group($rows) as $vendor) {
            $presented = $this->present($vendor, $today);
            $vendors[] = $presented;
            if ($presented['status'] === 'active') {
                $active++;
            }
            if ($this->hasDated($presented['current'])) {
                $ending++;
            }
            foreach ($this->placement->covering($vendor['assignments'], $today) as $assignment) {
                $occupied[$assignment['booth_id']] = true;
            }
        }

        $open = $this->openBooths($booths, $occupied);

        return [
            'stats' => [
                'vendors' => count($vendors),
                'active' => $active,
                'ending' => $ending,
                'open_booths' => count($open),
            ],
            'open' => $open,
            'vendors' => $vendors,
        ];
    }

    /**
     * @param list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   status: string,
     *   status_label: string,
     *   status_badge: string,
     *   since: string,
     *   booths: list<array{id: string, code: string, occupancy: string}>,
     *   current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     * }> $vendors
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   status: string,
     *   status_label: string,
     *   status_badge: string,
     *   since: string,
     *   booths: list<array{id: string, code: string, occupancy: string}>,
     *   current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     * }>
     */
    public function matching(array $vendors, string $status, string $query): array
    {
        $status = $this->selected($status);
        $needle = strtolower($query);
        $visible = [];
        foreach ($vendors as $vendor) {
            if (!$this->facet($status, $vendor)) {
                continue;
            }
            if (!$this->named($vendor['code'], $vendor['name'], $needle)) {
                continue;
            }
            $visible[] = $vendor;
        }

        return $visible;
    }

    public function selected(string $status): string
    {
        return isset(self::FILTERS[$status]) ? $status : '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   assignments: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     * }>
     */
    private function group(array $rows): array
    {
        $vendors = [];
        foreach ($rows as $row) {
            $id = $this->text($row['vendor_id'] ?? null);
            if (!isset($vendors[$id])) {
                $vendors[$id] = [
                    'id' => $id,
                    'code' => $this->text($row['vendor_code'] ?? null),
                    'name' => $this->text($row['vendor_name'] ?? null),
                    'assignments' => [],
                ];
            }
            $assignment = $this->assignment($row);
            if ($assignment === null) {
                continue;
            }
            $vendors[$id]['assignments'][] = $assignment;
        }

        return array_values($vendors);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}|null
     */
    private function assignment(array $row): ?array
    {
        if (($row['assignment_id'] ?? null) === null) {
            return null;
        }
        $starts = $this->date($row['starts_on'] ?? null);
        if ($starts === null) {
            throw new InvalidArgumentException('an assignment needs a start date');
        }
        $ends = $this->date($row['ends_on'] ?? null);

        return [
            'id' => $this->text($row['assignment_id']),
            'booth_id' => $this->text($row['booth_id'] ?? null),
            'booth_code' => $this->text($row['booth_code'] ?? null),
            'starts_on' => $starts,
            'ends_on' => $ends ?? '',
        ];
    }

    /**
     * @param array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   assignments: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     * } $vendor
     * @return array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   status: string,
     *   status_label: string,
     *   status_badge: string,
     *   since: string,
     *   booths: list<array{id: string, code: string, occupancy: string}>,
     *   current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     * }
     */
    private function present(array $vendor, string $today): array
    {
        $current = $this->placement->covering($vendor['assignments'], $today);
        $upcoming = $this->placement->upcoming($vendor['assignments'], $today);

        return [
            'id' => $vendor['id'],
            'code' => $vendor['code'],
            'name' => $vendor['name'],
            'status' => 'active',
            'status_label' => 'Active',
            'status_badge' => 'badge-success',
            'since' => $this->placement->since($current),
            'booths' => $this->placement->held($current, $upcoming),
            'current' => $current,
            'upcoming' => $upcoming,
            'past' => $this->placement->past($vendor['assignments'], $today),
        ];
    }

    /**
     * @param array{
     *   status: string,
     *   current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>,
     *   upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>
     * } $vendor
     */
    private function facet(string $filter, array $vendor): bool
    {
        if ($filter === 'active') {
            return $vendor['status'] === 'active';
        }
        if ($filter === 'ending') {
            return $this->hasDated($vendor['current']);
        }
        if ($filter === 'incoming') {
            return $vendor['upcoming'] !== [];
        }

        return true;
    }

    private function named(string $code, string $name, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return str_contains(strtolower($code), $needle) || str_contains(strtolower($name), $needle);
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}> $assignments
     */
    private function hasDated(array $assignments): bool
    {
        foreach ($assignments as $assignment) {
            if ($assignment['ends_on'] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $booths
     * @param array<string, true> $occupied
     * @return list<array{id: string, code: string, name: string}>
     */
    private function openBooths(array $booths, array $occupied): array
    {
        $open = [];
        foreach ($booths as $booth) {
            $id = $this->text($booth['id'] ?? null);
            if (isset($occupied[$id])) {
                continue;
            }
            $code = $booth['code'] ?? '';
            $name = $booth['name'] ?? '';
            $open[] = [
                'id' => $id,
                'code' => is_string($code) ? $code : '',
                'name' => is_string($name) ? $name : '',
            ];
        }

        return $open;
    }

    private function requireDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('today is YYYY-MM-DD');
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new InvalidArgumentException('an assignment date is YYYY-MM-DD');
        }

        return substr($value, 0, 10);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('a vendor field is missing');
        }

        return $value;
    }
}
