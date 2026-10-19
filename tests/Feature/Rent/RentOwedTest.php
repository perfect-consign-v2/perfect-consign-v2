<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Rent\RentOwed;
use PerfectConsign\Feature\Rent\RentPayment;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use PHPUnit\Framework\TestCase;

final class RentOwedTest extends TestCase
{
    private const VENDOR = '01900000-0000-7000-8000-0000000000v1';

    private const BOOTH = '01900000-0000-7000-8000-0000000000b1';

    private const OTHER = '01900000-0000-7000-8000-0000000000b2';

    /** @var list<mixed> */
    private array $lateParams = [];

    public function testOneCentOfPrepaidRentIsStillOwed(): void
    {
        $figure = $this->figure(true, 1, self::VENDOR, '', '2026-10-18', 1, 'Ada', [
            ['id' => self::BOOTH, 'code' => 'A1'],
        ]);

        self::assertNotNull($figure);
        self::assertSame(1, $figure['owed']);
        self::assertSame('A1', $figure['booth']);
        self::assertSame([self::VENDOR], $this->lateParams);
    }

    public function testAnOpenBalanceOfOneCentUsesTheChosenBooth(): void
    {
        $figure = $this->figure(false, 1, self::VENDOR, self::OTHER, '2026-10-18', 1, 'Ada', [
            ['id' => self::BOOTH, 'code' => 'A1'],
            ['id' => self::OTHER, 'code' => 'B2'],
        ]);

        self::assertNotNull($figure);
        self::assertSame(self::OTHER, $figure['booth_id']);
        self::assertSame(1, $figure['owed']);
    }

    public function testABlankVendorIsReadFromTheBooth(): void
    {
        $figure = $this->figure(false, 1, '', self::BOOTH, '2026-10-18', 1, 'Ada', [
            ['id' => self::BOOTH, 'code' => 'A1'],
        ], [], self::VENDOR);

        self::assertNotNull($figure);
        self::assertSame(self::VENDOR, $figure['vendor_id']);
    }

    public function testNothingIsOwedWithoutABalanceANameOrABooth(): void
    {
        self::assertNull($this->figure(false, 1, self::VENDOR, self::BOOTH, '2026-10-18', 0, 'Ada', [
            ['id' => self::BOOTH, 'code' => 'A1'],
        ]));
        self::assertNull($this->figure(false, 1, self::VENDOR, self::BOOTH, '2026-10-18', 1, null, [
            ['id' => self::BOOTH, 'code' => 'A1'],
        ]));
        self::assertNull($this->figure(false, 1, '', '', '2026-10-18', 1, 'Ada', [
            ['id' => self::BOOTH, 'code' => 'A1'],
        ], [], self::VENDOR));
        self::assertNull($this->figure(false, 1, '', self::BOOTH, 'not-a-day', 1, 'Ada', [
            ['id' => self::BOOTH, 'code' => 'A1'],
        ], [], self::VENDOR));
    }

    public function testABadDateDoesNotReplaceThePrepaidBooth(): void
    {
        $figure = $this->figure(true, 1, self::VENDOR, '', '2026-10-18', 1, 'Ada', [], [
            ['code' => 'OLD'],
        ]);

        self::assertNotNull($figure);
        self::assertSame('OLD', $figure['booth']);
    }

    /**
     * @param list<array{id: string, code: string}> $spots
     * @param list<array{code: string}> $assigned
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string}|null
     */
    private function figure(
        bool $prepaid,
        int $owes,
        string $vendorId,
        string $boothId,
        string $today,
        int $balance,
        ?string $name,
        array $spots,
        array $assigned = [],
        string $found = '',
    ): ?array {
        $this->lateParams = [];
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(
            function (string $sql, array $params) use ($spots, $assigned, $found, $name, $balance, $owes): array {
                if (str_contains($sql, 'payout_runs')) {
                    return [];
                }
                if (str_contains($sql, 'owe_amount')) {
                    return [[
                        'payable' => 0,
                        'rent' => 0,
                        'check_amount' => 0,
                        'owe_amount' => $owes,
                        'settled_minor' => 0,
                        'rent_from_sales' => false,
                    ]];
                }
                if (str_contains($sql, 'pack.owes')) {
                    return [[
                        'vendor_id' => self::VENDOR,
                        'code' => 'A',
                        'name' => 'Ada',
                        'booth_id' => self::BOOTH,
                        'booth' => 'Front',
                        'owes' => $owes,
                    ]];
                }
                if (str_contains($sql, 'late_minor')) {
                    $this->lateParams = $params;

                    return [['late_minor' => 0]];
                }
                if (str_contains($sql, 'LIMIT 1')) {
                    return $found === '' ? [] : [['vendor_id' => $found]];
                }
                if (str_contains($sql, 'open_minor')) {
                    return [['open_minor' => $balance]];
                }
                if (str_contains($sql, 'vendor_name')) {
                    return $name === null ? [] : [['vendor_name' => $name]];
                }
                if (str_contains($sql, 'HAVING sum')) {
                    return $spots;
                }
                if (str_contains($sql, 'starts_on')) {
                    return $assigned;
                }

                return [];
            }
        );
        $statements->method('rows')->willReturn([[
            'operating_mode' => 'both',
            'payout_cadence' => 'monthly',
            'rent_prepaid_from_prior_sales' => $prepaid ? 't' : 'f',
            'booth_rentals_enabled' => 't',
            'rent_cycle_day' => 1,
            'late_fees_enabled' => 't',
            'late_fee_grace_days' => 0,
            'late_fee_first_minor' => 1,
            'late_fee_next_minor' => 1,
        ]]);
        $payouts = new PayoutStore(
            $this->createStub(PDO::class),
            $statements,
            new PayoutRunStore($statements, new OccupiedPack($statements)),
            new PayoutWindow(),
        );

        return (new RentOwed($statements, $payouts, new MallPayoutSettings($statements), new RentPayment()))
            ->figure($vendorId, $boothId, $today);
    }
}
