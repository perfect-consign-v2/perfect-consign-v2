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
        self::assertSame('Next', $today['incoming']);
        $after = BoothMap::occupancy($stays, 'a', '2026-09-27');
        self::assertSame('occupied', $after['status']);
        self::assertSame('Next', $after['vendor']);
    }

    public function testAnUnplacedBoothIsLeftOffTheMap(): void
    {
        $tiles = BoothMap::tiles(
            [
                ['id' => 'a', 'code' => 'A1', 'name' => 'Front'],
                ['id' => 'b', 'code' => 'B1', 'name' => 'Back', 'x' => 4, 'z' => null],
            ],
            [],
            '2026-09-26'
        );
        self::assertSame([], $tiles);
    }

    public function testAPlacedBoothKeepsItsFeetAndStanding(): void
    {
        $tiles = BoothMap::tiles(
            [
                ['id' => 'a', 'code' => 'A1', 'name' => 'Front', 'x' => 12, 'z' => 8],
                ['id' => 'b', 'code' => 'B1', 'name' => 'Back', 'x' => 0, 'z' => 0],
            ],
            [],
            '2026-09-26'
        );
        self::assertSame('available', $tiles[0]['status']);
        self::assertNull($tiles[0]['vendor']);
        self::assertSame('A1', $tiles[0]['label']);
        self::assertSame(12, $tiles[0]['x']);
        self::assertSame(8, $tiles[0]['z']);
        self::assertSame(10, $tiles[0]['width']);
        self::assertSame(10, $tiles[0]['depth']);
        self::assertSame(0, $tiles[1]['x']);
        self::assertSame(0, $tiles[1]['z']);
    }

    public function testABoothSizeReplacesTheMallDefault(): void
    {
        $tiles = BoothMap::tiles(
            [
                ['id' => 'a', 'code' => 'A1', 'name' => 'Front', 'width' => 20, 'depth' => 10, 'x' => 2, 'z' => 2],
            ],
            [],
            '2026-09-26',
            10,
            10
        );
        self::assertSame(20, $tiles[0]['width']);
        self::assertSame(10, $tiles[0]['depth']);
    }

    public function testZeroOrNegativeFeetUseTheMallDefault(): void
    {
        $tiles = BoothMap::tiles(
            [
                ['id' => 'a', 'code' => 'A1', 'name' => 'Front', 'width' => 0, 'depth' => -4, 'x' => 1, 'z' => 1],
            ],
            [],
            '2026-09-26',
            10,
            8
        );
        self::assertSame(10, $tiles[0]['width']);
        self::assertSame(8, $tiles[0]['depth']);
    }

    public function testTheEarlierFutureStayIsTheIncomingVendor(): void
    {
        $stays = [
            ['boothId' => 'a', 'vendorName' => 'Later', 'startsOn' => '2026-10-02', 'endsOn' => null],
            ['boothId' => 'b', 'vendorName' => 'Other', 'startsOn' => '2026-10-01', 'endsOn' => null],
            ['boothId' => 'a', 'vendorName' => 'Soon', 'startsOn' => '2026-10-01', 'endsOn' => null],
            ['boothId' => 'a', 'vendorName' => 'Today', 'startsOn' => '2026-09-26', 'endsOn' => null],
        ];
        $spot = BoothMap::occupancy($stays, 'a', '2026-09-26');
        self::assertSame('occupied', $spot['status']);
        self::assertSame('Today', $spot['vendor']);
        $next = BoothMap::occupancy(
            [
                ['boothId' => 'a', 'vendorName' => 'Later', 'startsOn' => '2026-10-02', 'endsOn' => null],
                ['boothId' => 'a', 'vendorName' => 'Soon', 'startsOn' => '2026-10-01', 'endsOn' => null],
                ['boothId' => 'a', 'vendorName' => 'Also', 'startsOn' => '2026-10-01', 'endsOn' => null],
            ],
            'a',
            '2026-09-26'
        );
        self::assertSame('incoming', $next['status']);
        self::assertSame('Soon', $next['vendor']);
    }
}
