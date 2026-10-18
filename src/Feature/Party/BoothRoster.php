<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;

/**
 * One row per booth: who holds it, what it costs, and whether it can leave the list.
 */
final class BoothRoster
{
    public function __construct(
        private readonly BoothMeasures $measures,
        private readonly BoothStayLookup $stays = new BoothStayLookup(),
    ) {
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
     *   status_tip: ?string,
     *   incoming_vendor_id: ?string,
     *   incoming_vendor_code: ?string,
     *   incoming_tip: ?string,
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
     * @param array<string, mixed> $booth
     * @param list<array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}> $stays
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
     *   status_tip: ?string,
     *   incoming_vendor_id: ?string,
     *   incoming_vendor_code: ?string,
     *   incoming_tip: ?string,
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
        $spot = BoothMap::occupancy($this->stays->forMap($stays), $id, $today);
        $person = $this->stays->person($stays, $id, $today, $spot['status'], $spot['vendor']);
        if ($spot['vendor'] !== null && $person === null) {
            throw new InvalidArgumentException('a booth holder is missing');
        }
        $size = $this->measures->size($booth['width_ft'] ?? null, $booth['depth_ft'] ?? null, $defaultWidth, $defaultDepth);
        $incoming = $spot['status'] === 'ending' ? $this->stays->incoming($stays, $id, $today) : null;

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
            'status_tip' => $this->statusTip($spot['status'], $person),
            'incoming_vendor_id' => $incoming === null ? null : $incoming['vendorId'],
            'incoming_vendor_code' => $incoming === null ? null : $incoming['vendorCode'],
            'incoming_tip' => $incoming === null ? null : $this->incomingTip($incoming),
            'rent_minor' => $rent[$id] ?? null,
            'open_minor' => $open[$id] ?? null,
            'placed' => $this->measures->placed($booth['x_ft'] ?? null, $booth['z_ft'] ?? null),
            'deletable' => !isset($blocked[$id]),
        ];
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

    /**
     * @param array{vendorName: string, startsOn: string, endsOn: ?string}|null $person
     */
    private function statusTip(string $status, ?array $person): ?string
    {
        if ($person === null) {
            return null;
        }

        return match ($status) {
            'occupied' => $person['vendorName'],
            'ending' => $person['endsOn'] === null
                ? $person['vendorName']
                : $person['vendorName'] . ' · ends ' . $this->shownDay($person['endsOn']),
            'incoming' => $person['vendorName'] . ' · starts ' . $this->shownDay($person['startsOn']),
            default => null,
        };
    }

    /**
     * @param array{vendorName: string, startsOn: string} $incoming
     */
    private function incomingTip(array $incoming): string
    {
        return $incoming['vendorName'] . ' · starts ' . $this->shownDay($incoming['startsOn']);
    }

    private function shownDay(string $day): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $match) !== 1) {
            throw new InvalidArgumentException('a booth date is YYYY-MM-DD');
        }

        return $match[2] . '-' . $match[3] . '-' . $match[1];
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('a booth field is missing');
        }

        return $value;
    }
}
