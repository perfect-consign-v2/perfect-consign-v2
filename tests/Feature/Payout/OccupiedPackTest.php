<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PDO;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class OccupiedPackTest extends TestCase
{
    public function testASecondBoothStaysOnTheSameVendor(): void
    {
        $row = OccupiedPack::withBooth($this->paid('F-1', 'booth-f'), $this->stay('D-1', 'booth-d'));

        self::assertSame('D-1, F-1', $row['booth']);
        self::assertSame('booth-f', $row['boothId']);
        self::assertSame(156325, $row['check']);
        self::assertSame(84000, $row['owes']);
    }

    public function testABoothAlreadyListedIsNotRepeated(): void
    {
        $row = OccupiedPack::withBooth($this->paid('D-1, F-1', 'booth-d'), $this->stay('F-1', 'booth-f'));

        self::assertSame('D-1, F-1', $row['booth']);
        self::assertSame('booth-d', $row['boothId']);
    }

    public function testAVendorWithNoPayoutRowIsStillOneLine(): void
    {
        $row = OccupiedPack::withBooth(null, $this->stay('A-2', 'booth-a'));

        self::assertSame('A-2', $row['booth']);
        self::assertSame('booth-a', $row['boothId']);
        self::assertSame(0, $row['check']);
        self::assertSame(0, $row['owes']);
        self::assertSame(0, $row['payable']);
    }

    public function testAnEmptyBoothOnThePayoutRowTakesTheOccupiedBooth(): void
    {
        $row = OccupiedPack::withBooth($this->paid('', ''), $this->stay('C-1', 'booth-c'));

        self::assertSame('C-1', $row['booth']);
        self::assertSame('booth-c', $row['boothId']);
        self::assertSame(156325, $row['check']);
    }

    public function testABlankStayLeavesTheBoothAlreadyOnTheRow(): void
    {
        $row = OccupiedPack::withBooth($this->paid('F-1', 'booth-f'), $this->stay('', 'booth-x'));

        self::assertSame('F-1', $row['booth']);
        self::assertSame('booth-f', $row['boothId']);
        self::assertSame(156325, $row['check']);
    }

    public function testPackRowsForOneVendorCollapseAndAStayIsAddedInBoothOrder(): void
    {
        $pack = new OccupiedPack($this->stays([
            [
                'vendor_id' => 'vendor-max',
                'code' => 'MAX',
                'name' => 'Max Reed',
                'booth' => 'B-1',
                'booth_id' => 'booth-b',
            ],
            [
                'vendor_id' => 'vendor-max',
                'code' => 'MAX',
                'name' => 'Max Reed',
                'booth' => '',
                'booth_id' => '',
            ],
            [
                'vendor_id' => 'vendor-nye',
                'code' => 'NYE',
                'name' => 'Nye Cole',
                'booth' => 'C-3',
                'booth_id' => 'booth-c',
            ],
        ]));

        $rows = $pack->fill([
            $this->paid('A-10', 'booth-a10'),
            $this->paid('A-2', 'booth-a2'),
            $this->paid('E-4', 'booth-e', 'vendor-other', 'OTH', 'Oth Lane'),
        ], '2026-10-16');

        self::assertCount(3, $rows);
        self::assertSame('vendor-max', $rows[0]['vendor_id']);
        self::assertSame('A-2, A-10, B-1', $rows[0]['booth']);
        self::assertSame('booth-a10', $rows[0]['boothId']);
        self::assertSame(156325, $rows[0]['check']);
        self::assertSame('vendor-other', $rows[1]['vendor_id']);
        self::assertSame('E-4', $rows[1]['booth']);
        self::assertSame(156325, $rows[1]['check']);
        self::assertSame('vendor-nye', $rows[2]['vendor_id']);
        self::assertSame('C-3', $rows[2]['booth']);
        self::assertSame(0, $rows[2]['check']);
        self::assertSame(0, $rows[2]['payable']);
    }

    /**
     * @return array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}
     */
    private function paid(
        string $booth,
        string $boothId,
        string $vendorId = 'vendor-max',
        string $code = 'MAX',
        string $name = 'Max Reed',
    ): array {
        return [
            'vendor_id' => $vendorId,
            'code' => $code,
            'name' => $name,
            'booth' => $booth,
            'boothId' => $boothId,
            'mode' => 'pay',
            'prior' => 0,
            'charged' => 0,
            'paid' => 0,
            'payable' => 0,
            'rent' => 84000,
            'monthRent' => 23000,
            'check' => 156325,
            'owes' => 84000,
            'settled' => 0,
            'adjustment' => 0,
            'note' => '',
        ];
    }

    /**
     * @return array{vendor_id: string, code: string, name: string, booth: string, booth_id: string}
     */
    private function stay(string $booth, string $boothId): array
    {
        return [
            'vendor_id' => 'vendor-max',
            'code' => 'MAX',
            'name' => 'Max Reed',
            'booth' => $booth,
            'booth_id' => $boothId,
        ];
    }

    /**
     * @param list<array{vendor_id: string, code: string, name: string, booth: string, booth_id: string}> $rows
     */
    private function stays(array $rows): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class($pdo, $rows) extends Statements {
            /**
             * @param list<array{vendor_id: string, code: string, name: string, booth: string, booth_id: string}> $rows
             */
            public function __construct(PDO $pdo, private readonly array $rows)
            {
                parent::__construct($pdo);
            }

            /**
             * @param list<mixed> $params
             * @return list<array<string, mixed>>
             */
            public function bound(string $sql, array $params): array
            {
                return $this->rows;
            }
        };
    }
}
