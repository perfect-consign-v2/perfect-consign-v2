<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Feature\Party\VendorPlacement;
use PerfectConsign\Feature\Party\VendorRoster;
use PHPUnit\Framework\TestCase;

final class VendorRosterTest extends TestCase
{
    public function testAVendorWithNoBoothIsUnassigned(): void
    {
        $page = $this->roster()->assemble([
            $this->vendor('v1', 'ada', 'Ada'),
        ], [
            ['id' => 'b1'],
        ], '2026-09-27');

        self::assertSame(1, $page['stats']['vendors']);
        self::assertSame(1, $page['stats']['active']);
        self::assertSame(1, $page['stats']['open_booths']);
        self::assertSame('b1', $page['open'][0]['id']);
        self::assertSame('active', $page['vendors'][0]['status']);
        self::assertSame('Active', $page['vendors'][0]['status_label']);
        self::assertSame([], $page['vendors'][0]['booths']);
    }

    public function testCoverageSetsActiveEndingAndIncoming(): void
    {
        $page = $this->roster()->assemble([
            $this->vendor('v1', 'ada', 'Ada', 'a1', 'b1', 'North', '2020-01-01', null),
            $this->vendor('v2', 'bea', 'Bea', 'a2', 'b2', 'South', '2020-01-01', '2026-09-30'),
            $this->vendor('v3', 'cy', 'Cy', 'a3', 'b3', 'East', '2026-10-01', null),
            $this->vendor('v4', 'dee', 'Dee', 'a4', 'b4', 'West', '2020-01-01', '2026-01-01'),
        ], [
            ['id' => 'b1'],
            ['id' => 'b2'],
            ['id' => 'b3'],
            ['id' => 'b4'],
            ['id' => 'b5'],
        ], '2026-09-27');

        $byCode = [];
        foreach ($page['vendors'] as $vendor) {
            $byCode[$vendor['code']] = $vendor;
        }
        self::assertSame('active', $byCode['ada']['status']);
        self::assertSame('2020-01-01', $byCode['ada']['since']);
        self::assertSame('North', $byCode['ada']['booths'][0]['code']);
        self::assertSame('active', $byCode['bea']['status']);
        self::assertSame('active', $byCode['cy']['status']);
        self::assertSame('East', $byCode['cy']['booths'][0]['code']);
        self::assertSame('', $byCode['cy']['since']);
        self::assertSame('active', $byCode['dee']['status']);
        self::assertSame([], $byCode['dee']['current']);
        self::assertSame('a4', $byCode['dee']['past'][0]['id']);
        self::assertSame(3, $page['stats']['open_booths']);
        self::assertSame(4, $page['stats']['active']);
        self::assertSame(1, $page['stats']['ending']);
        self::assertSame(['bea'], array_column($this->roster()->matching($page['vendors'], 'ending', ''), 'code'));
        self::assertSame(['cy'], array_column($this->roster()->matching($page['vendors'], 'incoming', ''), 'code'));
    }

    public function testTheFilterKeepsANameOrCodeAndAStatus(): void
    {
        $roster = $this->roster();
        $page = $roster->assemble([
            $this->vendor('v1', 'ada', 'Ada'),
            $this->vendor('v2', 'bea', 'Bea Lane'),
        ], [], '2026-09-27');
        $named = $roster->matching($page['vendors'], '', 'lane');
        self::assertCount(1, $named);
        self::assertSame('bea', $named[0]['code']);
        $coded = $roster->matching($page['vendors'], 'active', 'ADA');
        self::assertCount(1, $coded);
        self::assertSame('ada', $coded[0]['code']);
        self::assertSame('', $roster->selected('nope'));
        self::assertSame('active', $roster->selected('active'));
    }

    public function testAVendorWithSeveralBoothsAppearsInEachFilter(): void
    {
        $roster = $this->roster();
        $page = $roster->assemble([
            $this->vendor('v5', '11', 'Sally', 'a5', 'b6', 'C-1', '2026-09-01', null),
            $this->vendor('v5', '11', 'Sally', 'a6', 'b7', 'A-1', '2026-09-27', '2026-09-30'),
            $this->vendor('v5', '11', 'Sally', 'a7', 'b8', 'B-2', '2026-10-01', '2026-10-31'),
        ], [], '2026-09-27');
        $sally = $page['vendors'][0];
        self::assertSame('active', $sally['status']);
        self::assertSame(['C-1', 'A-1', 'B-2'], array_column($sally['booths'], 'code'));
        self::assertSame(['occupied', 'ending', 'incoming'], array_column($sally['booths'], 'occupancy'));
        self::assertSame(['11'], array_column($roster->matching($page['vendors'], 'ending', ''), 'code'));
        self::assertSame(['11'], array_column($roster->matching($page['vendors'], 'incoming', ''), 'code'));
        self::assertSame(['11'], array_column($roster->matching($page['vendors'], 'active', ''), 'code'));
        self::assertSame('', $roster->selected('unassigned'));
        self::assertSame(1, $page['stats']['active']);
        self::assertSame(1, $page['stats']['ending']);
    }

    public function testTodayMustBeADate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->roster()->assemble([], [], 'today');
    }

    private function roster(): VendorRoster
    {
        return new VendorRoster(new VendorPlacement());
    }

    /**
     * @return array<string, mixed>
     */
    private function vendor(
        string $id,
        string $code,
        string $name,
        ?string $assignmentId = null,
        ?string $boothId = null,
        ?string $boothCode = null,
        ?string $startsOn = null,
        ?string $endsOn = null,
    ): array {
        return [
            'vendor_id' => $id,
            'vendor_code' => $code,
            'vendor_name' => $name,
            'assignment_id' => $assignmentId,
            'booth_id' => $boothId,
            'booth_code' => $boothCode,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ];
    }
}
