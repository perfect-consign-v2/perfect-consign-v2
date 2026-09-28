<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;

/**
 * One row per booth: who holds it, what it costs, and whether it can leave the list.
 */
final class BoothRoster
{
    public function __construct(private readonly BoothMeasures $measures)
    {
    }

    /**
     * @param list<array<string, mixed>> $booths
     * @param list<array<string, mixed>> $stays
     * @param list<array<string, mixed>> $rates
     * @param list<array<string, mixed>> $openRent
     * @param list<array<string, mixed>> $blocked
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }>
     */
    public function assemble(
        array $booths,
        array $stays,
        array $rates,
        array $openRent,
        array $blocked,
        string $today,
        int $defaultWidth,
        int $defaultDepth,
    ): array {
        $this->measures->requireDate($today);
        $parsed = $this->measures->stays($stays);
        $rent = $this->measures->rent($rates, $today);
        $open = $this->measures->openBalances($openRent);
        $held = $this->measures->blocked($blocked);
        $rows = [];
        foreach ($booths as $booth) {
            $rows[] = $this->present($booth, $parsed, $rent, $open, $held, $today, $defaultWidth, $defaultDepth);
        }
        usort(
            $rows,
            static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code'])
        );

        return $rows;
    }

    /**
     * @param list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }> $rows
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }>
     */
    public function matching(array $rows, string $query): array
    {
        $needle = strtolower($query);
        if ($needle === '') {
            return $rows;
        }
        $visible = [];
        foreach ($rows as $row) {
            $named = str_contains(strtolower($row['code']), $needle) || str_contains(strtolower($row['name']), $needle);
            if ($named) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * @param list<array{id: string, deletable: bool}> $rows
     */
    public function confirming(array $rows, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($rows as $row) {
            if (strtolower($row['id']) === $needle && $row['deletable']) {
                return $row['id'];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $booth
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @param array<string, int> $rent
     * @param array<string, int> $open
     * @param array<string, true> $blocked
     * @return array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }
     */
    private function present(
        array $booth,
        array $stays,
        array $rent,
        array $open,
        array $blocked,
        string $today,
        int $defaultWidth,
        int $defaultDepth,
    ): array {
        $id = $this->text($booth['id'] ?? null);
        $spot = BoothMap::occupancy($this->forMap($stays), $id, $today);
        $person = $this->person($stays, $id, $today, $spot['status'], $spot['vendor']);
        if ($spot['vendor'] !== null && $person === null) {
            throw new InvalidArgumentException('a booth holder is missing');
        }
        $size = $this->measures->size($booth['width_ft'] ?? null, $booth['depth_ft'] ?? null, $defaultWidth, $defaultDepth);

        return [
            'id' => $id,
            'code' => $this->text($booth['code'] ?? null),
            'name' => $this->text($booth['name'] ?? null),
            'width' => $size['width'],
            'depth' => $size['depth'],
            'status' => $spot['status'],
            'status_label' => $this->label($spot['status']),
            'vendor_id' => $person === null ? null : $person['vendorId'],
            'vendor_name' => $person === null ? null : $person['vendorName'],
            'rent_minor' => $rent[$id] ?? null,
            'open_minor' => $open[$id] ?? null,
            'placed' => $this->measures->placed($booth['x_ft'] ?? null, $booth['z_ft'] ?? null),
            'deletable' => !isset($blocked[$id]),
        ];
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}>
     */
    private function forMap(array $stays): array
    {
        $mapped = [];
        foreach ($stays as $stay) {
            $mapped[] = [
                'boothId' => $stay['boothId'],
                'vendorName' => $stay['vendorName'],
                'startsOn' => $stay['startsOn'],
                'endsOn' => $stay['endsOn'],
            ];
        }

        return $mapped;
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}|null
     */
    private function person(array $stays, string $boothId, string $today, string $status, ?string $vendorName): ?array
    {
        if ($vendorName === null) {
            return null;
        }
        if ($status === 'incoming') {
            return $this->incoming($stays, $boothId, $today);
        }

        return $this->covering($stays, $boothId, $today, $vendorName);
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}|null
     */
    private function covering(array $stays, string $boothId, string $today, string $vendorName): ?array
    {
        $covering = null;
        foreach ($stays as $stay) {
            if ($stay['boothId'] !== $boothId || $stay['vendorName'] !== $vendorName) {
                continue;
            }
            $open = $stay['endsOn'] === null || $stay['endsOn'] >= $today;
            if ($stay['startsOn'] <= $today && $open) {
                $covering = $stay;
            }
        }

        return $covering;
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}|null
     */
    private function incoming(array $stays, string $boothId, string $today): ?array
    {
        $next = null;
        foreach ($stays as $stay) {
            if ($stay['boothId'] !== $boothId || $stay['startsOn'] <= $today) {
                continue;
            }
            if ($next === null || $stay['startsOn'] < $next['startsOn']) {
                $next = $stay;
            }
        }

        return $next;
    }

    private function label(string $status): string
    {
        return match ($status) {
            'available' => 'Available',
            'occupied' => 'Occupied',
            'ending' => 'Ending',
            'incoming' => 'Incoming',
            default => throw new InvalidArgumentException('a booth standing is missing'),
        };
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('a booth field is missing');
        }

        return $value;
    }
}
