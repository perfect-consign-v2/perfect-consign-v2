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
        $covering = self::covering($stays, $boothId, $today);
        if ($covering !== null) {
            return [
                'status' => $covering['endsOn'] !== null ? 'ending' : 'occupied',
                'vendor' => $covering['vendorName'],
            ];
        }
        $next = self::incoming($stays, $boothId, $today);
        if ($next !== null) {
            return ['status' => 'incoming', 'vendor' => $next['vendorName']];
        }

        return ['status' => 'available', 'vendor' => null];
    }

    /**
     * @param list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}|null
     */
    private static function covering(array $stays, string $boothId, string $today): ?array
    {
        $covering = null;
        foreach ($stays as $stay) {
            if ($stay['boothId'] !== $boothId) {
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
     * @param list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}|null
     */
    private static function incoming(array $stays, string $boothId, string $today): ?array
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

    /**
     * @param list<array{id: string, code: string, name: string, width?: int|null, depth?: int|null}> $booths
     * @param list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return list<array{id: string, code: string, name: string, x: int, z: int, width: int, depth: int, status: string, vendor: ?string, label: string}>
     */
    public static function tiles(array $booths, array $stays, string $today, int $defaultWidth = 10, int $defaultDepth = 10): array
    {
        $tiles = [];
        $x = 2;
        $z = 2;
        $rowDepth = 0;
        foreach ($booths as $booth) {
            $spot = self::occupancy($stays, $booth['id'], $today);
            $width = self::feet($booth['width'] ?? null, $defaultWidth);
            $depth = self::feet($booth['depth'] ?? null, $defaultDepth);
            if ($x !== 2 && $x + $width > 120) {
                $x = 2;
                $z += $rowDepth + 2;
                $rowDepth = 0;
            }
            $vendor = $spot['vendor'];
            $tiles[] = [
                'id' => $booth['id'],
                'code' => $booth['code'],
                'name' => $booth['name'],
                'x' => $x,
                'z' => $z,
                'width' => $width,
                'depth' => $depth,
                'status' => $spot['status'],
                'vendor' => $vendor,
                'label' => $vendor === null ? $booth['code'] : $booth['code'] . ' — ' . $vendor,
            ];
            $rowDepth = max($rowDepth, $depth);
            $x += $width + 2;
        }

        return $tiles;
    }

    private static function feet(mixed $value, int $fallback): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return $fallback;
    }
}
