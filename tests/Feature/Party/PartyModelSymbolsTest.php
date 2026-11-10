<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\PartyRoleType;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PartyModelSymbolsTest extends TestCase
{
    public function testSellerPathsFollowOperatingMode(): void
    {
        $consignors = new SellerPaths($this->mall('consignor'));
        self::assertSame('consignors', $consignors->prefix());
        self::assertSame('/demo/consignors', $consignors->list('demo'));
        self::assertSame('/demo/inventory/consignors.json', $consignors->inventoryJson('demo'));
        self::assertSame('/demo/consignors/new', $consignors->createForm('demo'));

        $vendors = new SellerPaths($this->mall('booth_mall'));
        self::assertSame('vendors', $vendors->prefix());
        self::assertSame('/demo/vendors', $vendors->list('demo'));
        self::assertSame('/demo/inventory/vendors.json', $vendors->inventoryJson('demo'));
        self::assertSame('/demo/vendors', $vendors->createAction('demo'));
    }

    public function testPartyRoleTypeKeepsStoredVendorConstant(): void
    {
        self::assertSame('vendor', PartyRoleType::VENDOR);
        self::assertSame(['vendor'], PartyRoleType::fromAgg('vendor'));
        self::assertSame('consignor', PartyRoleType::displayName('vendor', true));
        self::assertSame('vendor', PartyRoleType::displayName('vendor', false));
        self::assertSame('consignor, customer', PartyRoleType::displayNames(['vendor', 'customer'], true));
    }

    private function mall(string $mode): MallSettings
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([[
            'operating_mode' => $mode,
            'time_zone' => 'UTC',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 5000,
            'floor_days' => 30,
            'cash_round_minor' => 0,
            'tax_bps' => 0,
            'use_color' => false,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => '',
            'booth_rentals_enabled' => $mode !== 'consignor',
            'rent_cycle_day' => 1,
            'consignment_enabled' => true,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
        ]]);

        return new MallSettings(
            $statements,
            (new ReflectionClass(CompanyStore::class))->newInstanceWithoutConstructor(),
            new MallDay($statements),
        );
    }
}
