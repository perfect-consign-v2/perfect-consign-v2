<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\ItemWriter;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ItemWriterTest extends TestCase
{
    public function testAnIntakeDateIsRequired(): void
    {
        try {
            $this->writer()->entry($this->draft(['intake_on' => 'not-a-day']));
            self::fail('a missing intake date was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter an intake date.', $error->getMessage());
        }
    }

    public function testACreateNeedsAPositiveQuantity(): void
    {
        try {
            $this->writer()->entry($this->draft(['quantity' => '0']));
            self::fail('a zero quantity create was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a quantity of at least 1.', $error->getMessage());
        }
    }

    public function testBrandIsRefusedWhenTheMallTurnedItOff(): void
    {
        try {
            $this->writer()->entry($this->draft([
                'brand_id' => '00000000-0000-7000-8000-0000000000b1',
            ]));
            self::fail('a brand was saved while brands are off');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Brand is turned off.', $error->getMessage());
        }
    }

    public function testAnUnknownBrandLabelIsRefused(): void
    {
        try {
            $this->writer(true)->entry($this->draft(['brand_label' => 'Unknown Brand']));
            self::fail('an unknown brand was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Add that brand before saving the item.', $error->getMessage());
        }
    }

    public function testAnAttributeIsRefusedWhenTheListIsOff(): void
    {
        try {
            $this->writer()->entry($this->draft([
                'attributes' => [
                    'color' => ['id' => '00000000-0000-7000-8000-0000000000c1', 'label' => ''],
                    'category' => ['id' => '', 'label' => ''],
                    'size' => ['id' => '', 'label' => ''],
                    'condition' => ['id' => '', 'label' => ''],
                    'department' => ['id' => '', 'label' => ''],
                ],
            ]));
            self::fail('a color was saved while colors are off');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Color is turned off.', $error->getMessage());
        }
    }

    public function testAnUnknownAttributeLabelIsRefused(): void
    {
        try {
            $this->writer(true)->entry($this->draft([
                'attributes' => [
                    'color' => ['id' => '', 'label' => 'Teal'],
                    'category' => ['id' => '', 'label' => ''],
                    'size' => ['id' => '', 'label' => ''],
                    'condition' => ['id' => '', 'label' => ''],
                    'department' => ['id' => '', 'label' => ''],
                ],
            ]));
            self::fail('an unknown color was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Add that color before saving the item.', $error->getMessage());
        }
    }

    public function testStoreTakeMustBeAPercent(): void
    {
        try {
            $this->writer()->entry($this->draft(['store_take' => 'nope']));
            self::fail('a non-percent store take was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('store take is a percent from 0 to 100', $error->getMessage());
        }
    }

    public function testStoreStockDoesNotKeepAVendor(): void
    {
        $entry = $this->writer()->entry($this->draft(['placement' => 'store']));

        self::assertSame('', $entry->vendorId);
    }

    public function testExpireOnTheIntakeDayIsKept(): void
    {
        $entry = $this->writer()->entry($this->draft(['expires_on' => '2026-09-01']));

        self::assertSame('2026-09-01', $entry->dates->expiresOn);
    }

    public function testExpireCannotBeBeforeIntake(): void
    {
        try {
            $this->writer()->entry($this->draft(['expires_on' => '2026-08-01']));
            self::fail('an expire date before intake was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('The expire date is before the intake date.', $error->getMessage());
        }
    }

    public function testExpireMustBeADate(): void
    {
        try {
            $this->writer()->entry($this->draft(['expires_on' => 'October']));
            self::fail('a non-date expire was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter an expire date.', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $override
     * @return array{
     *     id: string,
     *     code: string,
     *     barcode: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     vendor_id: string,
     *     vendor_label: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     store_take: string,
     *     intake_on: string,
     *     expires_on: string,
     *     fill_expire: string
     * }
     */
    private function draft(array $override = []): array
    {
        return array_merge([
            'id' => '',
            'code' => 't1',
            'barcode' => '',
            'description' => 'Coat',
            'price' => '10.00',
            'quantity' => '1',
            'vendor_id' => '00000000-0000-7000-8000-0000000000v1',
            'vendor_label' => '',
            'brand_id' => '',
            'brand_label' => '',
            'attributes' => [
                'color' => ['id' => '', 'label' => ''],
                'category' => ['id' => '', 'label' => ''],
                'size' => ['id' => '', 'label' => ''],
                'condition' => ['id' => '', 'label' => ''],
                'department' => ['id' => '', 'label' => ''],
            ],
            'store_take' => '',
            'intake_on' => '2026-09-01',
            'expires_on' => '',
            'fill_expire' => '',
        ], $override);
    }

    private function writer(bool $listsOn = false): ItemWriter
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([[
            'time_zone' => 'UTC',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 5000,
            'floor_days' => 1,
            'cash_round_nickel' => false,
            'tax_bps' => 0,
            'use_color' => $listsOn,
            'use_category' => $listsOn,
            'use_size' => $listsOn,
            'use_condition' => $listsOn,
            'use_department' => $listsOn,
            'use_brand' => $listsOn,
            'label_stock' => '',
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'receipt_footer' => '',
        ]]);
        $statements->method('bound')->willReturn([]);
        $mall = new MallSettings(
            $statements,
            (new ReflectionClass(CompanyStore::class))->newInstanceWithoutConstructor(),
        );

        return new ItemWriter(
            new IntakeCatalog($statements),
            new NameListStore($statements, new CatalogLinks($statements)),
            $mall,
            new StoreTake(),
        );
    }
}
