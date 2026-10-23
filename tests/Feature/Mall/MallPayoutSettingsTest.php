<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class MallPayoutSettingsTest extends TestCase
{
    public function testLateFeeGraceKeepsZeroAndTwentyEight(): void
    {
        self::assertSame(28, $this->settings($this->row(28, false))->lateFee()['grace']);
        self::assertSame(0, $this->settings($this->row(-1, false))->lateFee()['grace']);
        self::assertSame(0, $this->settings($this->row(0, false))->lateFee()['grace']);
    }

    public function testEachStoredFlagCountsAsOn(): void
    {
        foreach ([true, 1, '1', 't', 'true'] as $flag) {
            self::assertTrue($this->settings($this->row(0, $flag))->rentPrepaid());
        }
        self::assertFalse($this->settings($this->row(0, 'f'))->rentPrepaid());
        self::assertFalse($this->settings($this->row(0, 0))->rentPrepaid());
    }

    public function testTheOperatingModeSetsRentAndConsignment(): void
    {
        self::assertSame(
            ['rentals' => false, 'consignment' => true],
            MallPayoutSettings::capabilities('consignor')
        );
        self::assertSame(
            ['rentals' => true, 'consignment' => false],
            MallPayoutSettings::capabilities('booth_mall')
        );
        self::assertSame(
            ['rentals' => true, 'consignment' => true],
            MallPayoutSettings::capabilities('both')
        );
        self::assertSame(
            ['rentals' => true, 'consignment' => true],
            MallPayoutSettings::capabilities('other')
        );
        self::assertSame('1', MallPayoutSettings::getPostedConsignment('', '1'));
        self::assertSame('', MallPayoutSettings::getPostedConsignment('', ''));
        self::assertSame('1', MallPayoutSettings::getPostedConsignment('consignor', '0'));
        self::assertSame('0', MallPayoutSettings::getPostedConsignment('booth_mall', '1'));
        self::assertSame('1', MallPayoutSettings::getPostedConsignment('both', '0'));
        $rows = $this->createStub(Statements::class);
        $rows->method('rows')->willReturnOnConsecutiveCalls(
            [['operating_mode' => 'consignor']],
            [['operating_mode' => 'consignor']],
            [['operating_mode' => 'both']],
            [['operating_mode' => 'both']],
        );
        $mall = (new ReflectionClass(MallSettings::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(MallSettings::class, 'statements'))->setValue($mall, $rows);
        self::assertTrue($mall->isConsignmentOnly());
        self::assertSame('Consignors', $mall->getPartyLabel('Vendors', 'Consignors'));
        self::assertFalse($mall->isConsignmentOnly());
        self::assertSame('Vendors', $mall->getPartyLabel('Vendors', 'Consignors'));
    }

    public function testAPostedShopKeepsTheStoredFlagsUntilTheFormSendsThem(): void
    {
        $settings = $this->settings($this->row(0, false) + [
            'consignment_enabled' => true,
            'store_stock_enabled' => false,
        ]);
        self::assertSame(
            ['consignment' => true, 'store' => false, 'days' => 4],
            $settings->getPostedShop('', '0', '1', 4)
        );
        self::assertSame(
            ['consignment' => false, 'store' => true, 'days' => 4],
            $settings->getPostedShop('1', '0', '1', 4)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function settings(array $row): MallPayoutSettings
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class ($pdo, $row) extends Statements {
            /**
             * @param array<string, mixed> $row
             */
            public function __construct(PDO $pdo, private readonly array $row)
            {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [$this->row];
            }
        };

        return new MallPayoutSettings($statements);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $grace, mixed $prepaid): array
    {
        return [
            'operating_mode' => 'both',
            'payout_cadence' => 'monthly',
            'rent_prepaid_from_prior_sales' => $prepaid,
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'late_fees_enabled' => false,
            'late_fee_grace_days' => $grace,
            'late_fee_first_minor' => 0,
            'late_fee_next_minor' => 0,
        ];
    }
}
