<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Feature\Party\BoothMeasures;
use PerfectConsign\Feature\Party\BoothRoster;
use PHPUnit\Framework\TestCase;

final class BoothRosterTest extends TestCase
{
    private const ADA = '01900000-0000-7000-8000-0000000000a1';

    private const NORTH = '01900000-0000-7000-8000-0000000000b1';

    private const SOUTH = '01900000-0000-7000-8000-0000000000b2';

    private const EAST = '01900000-0000-7000-8000-0000000000b3';

    public function testStandingNamesTheHolderAndTheRentInEffect(): void
    {
        $rows = $this->roster()->assemble(
            [
                $this->booth(self::NORTH, 'A10', 'North', 12, 8, 4, 6),
                $this->booth(self::SOUTH, 'A2', 'South', null, null, null, null),
                $this->booth(self::EAST, 'A1', 'East', null, null, 0, 0),
            ],
            [
                $this->stay(self::NORTH, self::ADA, 'Ada', '2020-01-01', '2026-09-30'),
                $this->stay(self::SOUTH, self::ADA, 'Bea', '2026-10-01', null),
            ],
            [
                ['booth_id' => self::NORTH, 'amount_minor' => 9000],
            ],
            [
                ['booth_id' => self::NORTH, 'open_minor' => 9000],
                ['booth_id' => self::SOUTH, 'open_minor' => 0],
            ],
            [
                ['id' => self::NORTH],
            ],
            '2026-09-27',
            10,
            10
        );

        self::assertSame(['A1', 'A2', 'A10'], array_column($rows, 'code'));
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }
        self::assertSame('ending', $byCode['A10']['status']);
        self::assertSame('Ending', $byCode['A10']['status_label']);
        self::assertSame(self::ADA, $byCode['A10']['vendor_id']);
        self::assertSame('Ada', $byCode['A10']['vendor_name']);
        self::assertSame(9000, $byCode['A10']['rent_minor']);
        self::assertSame(9000, $byCode['A10']['open_minor']);
        self::assertTrue($byCode['A10']['placed']);
        self::assertFalse($byCode['A10']['deletable']);
        self::assertSame(12, $byCode['A10']['width']);
        self::assertSame(8, $byCode['A10']['depth']);

        self::assertSame('incoming', $byCode['A2']['status']);
        self::assertSame('Bea', $byCode['A2']['vendor_name']);
        self::assertNull($byCode['A2']['rent_minor']);
        self::assertNull($byCode['A2']['open_minor']);
        self::assertFalse($byCode['A2']['placed']);
        self::assertSame(10, $byCode['A2']['width']);
        self::assertTrue($byCode['A2']['deletable']);

        self::assertSame('available', $byCode['A1']['status']);
        self::assertNull($byCode['A1']['vendor_id']);
        self::assertTrue($byCode['A1']['placed']);
        self::assertSame(10, $byCode['A1']['width']);
        self::assertSame(10, $byCode['A1']['depth']);
    }

    public function testAnOpenStayIsOccupied(): void
    {
        $rows = $this->roster()->assemble(
            [$this->booth(self::NORTH, 'A1', 'North', null, null, null, null)],
            [$this->stay(self::NORTH, self::ADA, 'Ada', '2020-01-01', null)],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );

        self::assertSame('occupied', $rows[0]['status']);
        self::assertSame('Occupied', $rows[0]['status_label']);
        self::assertSame(self::ADA, $rows[0]['vendor_id']);
    }

    public function testSearchMatchesCodeOrName(): void
    {
        $rows = $this->roster()->assemble(
            [
                $this->booth(self::NORTH, 'A1', 'North', null, null, null, null),
                $this->booth(self::SOUTH, 'B1', 'South hall', null, null, null, null),
            ],
            [],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );

        self::assertSame(['A1'], array_column($this->roster()->matching($rows, 'a1'), 'code'));
        self::assertSame(['B1'], array_column($this->roster()->matching($rows, 'HALL'), 'code'));
        self::assertSame([], $this->roster()->matching($rows, 'nope'));
    }

    public function testDeleteIsOfferedOnlyForACleanBooth(): void
    {
        $rows = $this->roster()->assemble(
            [$this->booth(self::NORTH, 'A1', 'North', null, null, null, null)],
            [],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );

        self::assertSame(self::NORTH, $this->roster()->confirming($rows, self::NORTH));
        self::assertSame('', $this->roster()->confirming($rows, 'not-an-id'));
        $rows[0]['deletable'] = false;
        self::assertSame('', $this->roster()->confirming($rows, self::NORTH));
    }

    public function testOneCoordinateLeavesTheBoothOffTheMap(): void
    {
        $rows = $this->roster()->assemble(
            [[
                'id' => self::NORTH,
                'code' => 'A1',
                'name' => 'North',
                'width_ft' => '',
                'depth_ft' => '',
                'x_ft' => '4',
                'z_ft' => '',
            ]],
            [],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );

        self::assertFalse($rows[0]['placed']);
    }

    public function testBoundariesKeepTheStayThatCoversToday(): void
    {
        $rows = $this->roster()->assemble(
            [
                $this->booth(self::NORTH, 'A1', 'North', null, 8, null, null),
                $this->booth(self::SOUTH, 'A2', 'South', null, null, null, null),
                $this->booth(self::EAST, 'A3', 'East', null, null, null, null),
            ],
            [
                $this->stay(self::NORTH, self::ADA, 'Ada', '2026-09-27', '2026-09-27'),
                $this->stay(self::EAST, self::EAST, 'Ada', '2020-01-01', null),
                $this->stay(self::NORTH, self::ADA, 'Ada', '2026-10-01', null),
                $this->stay(self::SOUTH, self::ADA, 'Later', '2026-10-03', null),
                $this->stay(self::SOUTH, self::ADA, 'Soon', '2026-10-01', null),
                $this->stay(self::SOUTH, self::EAST, 'Soon', '2026-10-01', null),
                $this->stay(self::NORTH, self::EAST, 'Ada', '2026-10-03', null),
            ],
            [
                ['booth_id' => self::NORTH, 'amount_minor' => 1000],
            ],
            [],
            [],
            '2026-09-27',
            10,
            10
        );
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }
        self::assertSame('ending', $byCode['A1']['status']);
        self::assertSame('Ada', $byCode['A1']['vendor_name']);
        self::assertSame(self::ADA, $byCode['A1']['vendor_id']);
        self::assertSame(1000, $byCode['A1']['rent_minor']);
        self::assertSame(10, $byCode['A1']['width']);
        self::assertSame(10, $byCode['A1']['depth']);
        self::assertSame('incoming', $byCode['A2']['status']);
        self::assertSame('Soon', $byCode['A2']['vendor_name']);
        self::assertSame(self::ADA, $byCode['A2']['vendor_id']);
    }

    public function testABlankVendorNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roster()->assemble(
            [$this->booth(self::NORTH, 'A1', 'North', null, null, null, null)],
            [$this->stay(self::NORTH, self::ADA, '', '2026-09-01', null)],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );
    }

    public function testABadStayDateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roster()->assemble(
            [$this->booth(self::NORTH, 'A1', 'North', null, null, null, null)],
            [$this->stay(self::NORTH, self::ADA, 'Ada', 'September', null)],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );
    }

    public function testABlankBoothCodeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roster()->assemble(
            [['id' => self::NORTH, 'code' => '', 'name' => 'North']],
            [],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );
    }

    public function testAZeroFootBoothIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roster()->assemble(
            [$this->booth(self::NORTH, 'A1', 'North', 0, 8, null, null)],
            [],
            [],
            [],
            [],
            '2026-09-27',
            10,
            10
        );
    }

    public function testTodayMustBeADate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roster()->assemble([], [], [], [], [], 'yesterday', 10, 10);
    }

    /**
     * @return array<string, mixed>
     */
    private function booth(
        string $id,
        string $code,
        string $name,
        ?int $width,
        ?int $depth,
        ?int $x,
        ?int $z,
    ): array {
        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'width_ft' => $width,
            'depth_ft' => $depth,
            'x_ft' => $x,
            'z_ft' => $z,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stay(
        string $boothId,
        string $vendorId,
        string $vendorName,
        string $startsOn,
        ?string $endsOn,
    ): array {
        return [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ];
    }

    private function roster(): BoothRoster
    {
        return new BoothRoster(new BoothMeasures());
    }
}
