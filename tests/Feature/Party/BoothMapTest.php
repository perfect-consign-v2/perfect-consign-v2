<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\BoothMap;
use PHPUnit\Framework\TestCase;

final class BoothMapTest extends TestCase
{
    public function testLastDayOfAStayIsEndingAndTheNextStayIsNamed(): void
    {
        $stays = [
            [
                'boothId' => 'a',
                'vendorName' => 'Pat',
                'startsOn' => '2026-09-01',
                'endsOn' => '2026-09-26',
            ],
            [
                'boothId' => 'a',
                'vendorName' => 'Next',
                'startsOn' => '2026-09-27',
                'endsOn' => null,
            ],
        ];
        $today = BoothMap::occupancy($stays, 'a', '2026-09-26');
        self::assertSame('ending', $today['status']);
        self::assertSame('Pat', $today['vendor']);
        $after = BoothMap::occupancy($stays, 'a', '2026-09-27');
        self::assertSame('occupied', $after['status']);
        self::assertSame('Next', $after['vendor']);
    }

    public function testAnEmptyBoothIsAvailableAndTilesStayOnTheFloor(): void
    {
        $tiles = BoothMap::tiles(
            [
                ['id' => 'a', 'code' => 'A1', 'name' => 'Front'],
                ['id' => 'b', 'code' => 'B1', 'name' => 'Back'],
            ],
            [],
            '2026-09-26'
        );
        self::assertSame('available', $tiles[0]['status']);
        self::assertNull($tiles[0]['vendor']);
        self::assertSame('A1', $tiles[0]['label']);
        self::assertLessThanOrEqual(120, $tiles[1]['x'] + $tiles[1]['width']);
    }
}
