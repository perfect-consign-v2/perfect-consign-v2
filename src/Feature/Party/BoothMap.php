<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

final class BoothMap
{
    /**
     * @param list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{status: string, vendor: ?string}
     */
    public static function occupancy(array $stays, string $boothId, string $today): array
    {
        $covering = null;
        $next = null;
        foreach ($stays as $stay) {
            if ($stay['boothId'] !== $boothId) {
                continue;
            }
            $covers = $stay['startsOn'] <= $today && ($stay['endsOn'] === null || $stay['endsOn'] >= $today);
            if ($covers) {
                $covering = $stay;
                continue;
            }
            if ($stay['startsOn'] > $today && ($next === null || $stay['startsOn'] < $next['startsOn'])) {
                $next = $stay;
            }
        }
        if ($covering !== null) {
            return [
                'status' => $covering['endsOn'] !== null ? 'ending' : 'occupied',
                'vendor' => $covering['vendorName'],
            ];
        }
        if ($next !== null) {
            return ['status' => 'incoming', 'vendor' => $next['vendorName']];
        }

        return ['status' => 'available', 'vendor' => null];
    }

    /**
     * @param list<array{id: string, code: string, name: string}> $booths
     * @param list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return list<array{id: string, code: string, name: string, x: int, z: int, width: int, depth: int, status: string, vendor: ?string, label: string}>
     */
    public static function tiles(array $booths, array $stays, string $today): array
    {
        $tiles = [];
        $column = 0;
        $row = 0;
        foreach ($booths as $booth) {
            $spot = self::occupancy($stays, $booth['id'], $today);
            $x = 2 + ($column * 12);
            $z = 2 + ($row * 12);
            if ($x + 10 > 120) {
                $column = 0;
                $row++;
                $x = 2;
                $z = 2 + ($row * 12);
            }
            $vendor = $spot['vendor'];
            $tiles[] = [
                'id' => $booth['id'],
                'code' => $booth['code'],
                'name' => $booth['name'],
                'x' => $x,
                'z' => $z,
                'width' => 10,
                'depth' => 10,
                'status' => $spot['status'],
                'vendor' => $vendor,
                'label' => $vendor === null ? $booth['code'] : $booth['code'] . ' — ' . $vendor,
            ];
            $column++;
        }

        return $tiles;
    }
}
