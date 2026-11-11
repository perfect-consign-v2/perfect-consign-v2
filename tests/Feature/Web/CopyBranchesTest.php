<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PHPUnit\Framework\TestCase;

final class CopyBranchesTest extends TestCase
{
    public function testPayoutWordsFollowTheStore(): void
    {
        $history = $this->render('Feature/Payout/history.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'runs' => [],
            'excluded' => [],
            'error' => null,
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('Pay consignors', $history);
        self::assertStringContainsString('Consignor', $history);
        self::assertStringNotContainsString('Pay vendors', $history);

        $vendors = $this->render('Feature/Payout/history.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'runs' => [],
            'excluded' => [],
            'error' => null,
            'consignmentOnly' => false,
        ]);
        self::assertStringContainsString('Pay vendors', $vendors);
        self::assertStringContainsString('>Vendor<', $vendors);

        $pay = $this->render('Feature/Payout/payout.php', $this->payout(true));
        self::assertSame(3, substr_count($pay, 'Pay this consignor'));
        self::assertStringContainsString('>Consignor<', $pay);
        self::assertStringNotContainsString('Pay this vendor', $pay);
        self::assertStringNotContainsString('>Vendor<', $pay);

        $dealer = $this->render('Feature/Payout/payout.php', $this->payout(false));
        self::assertSame(3, substr_count($dealer, 'Pay this vendor'));
        self::assertStringContainsString('>Vendor<', $dealer);
        self::assertStringNotContainsString('Pay this consignor', $dealer);

        $sheet = $this->render('Feature/Payout/pay-vendors.php', $this->sheet(false));
        self::assertStringContainsString('>Booth<', $sheet);
        self::assertStringContainsString('>12<', $sheet);
        self::assertStringContainsString('$0.75', $sheet);
        self::assertStringContainsString('Owes the store', $sheet);

        $consignors = $this->render('Feature/Payout/pay-vendors.php', $this->sheet(true));
        self::assertStringNotContainsString('Booth', $consignors);
        self::assertStringNotContainsString('>12<', $consignors);
        self::assertStringNotContainsString('$0.75', $consignors);
        self::assertStringNotContainsString('Owes the store', $consignors);
        self::assertStringContainsString('Consignor', $consignors);
    }

    public function testTheRegisterNamesThePartyAndAZeroRoundStaysPlain(): void
    {
        $plain = $this->register(false, 100);
        self::assertStringContainsString('<label for="buyer">Vendor</label>', $plain);
        self::assertSame(2, substr_count($plain, 'Name or vendor number'));
        self::assertSame(0, substr_count($plain, 'Name or consignor number'));
        self::assertSame(1, substr_count($plain, 'Choose the vendor'));
        self::assertSame(0, substr_count($plain, 'Choose the consignor'));
        self::assertSame(1, substr_count($plain, '>Choose vendor<'));
        self::assertSame(0, substr_count($plain, '>Choose consignor<'));
        self::assertSame(1, substr_count($plain, 'the vendor’s default'));
        self::assertSame(0, substr_count($plain, 'the consignor’s default'));
        self::assertStringContainsString('>$0.00<', $plain);
        self::assertStringNotContainsString('>+$0.00<', $plain);

        $emptySales = $this->register(false, 100, 'buyer-1');
        self::assertStringContainsString('this vendor', $emptySales);
        self::assertStringNotContainsString('this consignor', $emptySales);
        self::assertSame(1, substr_count($emptySales, 'Clear vendor'));
        self::assertSame(0, substr_count($emptySales, 'Clear consignor'));

        $store = $this->register(true, 103);
        self::assertStringContainsString('<label for="buyer">Consignor</label>', $store);
        self::assertSame(2, substr_count($store, 'Name or consignor number'));
        self::assertSame(0, substr_count($store, 'Name or vendor number'));
        self::assertSame(1, substr_count($store, 'Choose the consignor'));
        self::assertSame(0, substr_count($store, 'Choose the vendor'));
        self::assertSame(1, substr_count($store, '>Choose consignor<'));
        self::assertSame(0, substr_count($store, '>Choose vendor<'));
        self::assertSame(1, substr_count($store, 'the consignor’s default'));
        self::assertSame(0, substr_count($store, 'the vendor’s default'));
        self::assertStringContainsString('>+$', $store);

        $storeSales = $this->register(true, 103, 'buyer-1');
        self::assertStringContainsString('this consignor', $storeSales);
        self::assertStringNotContainsString('this vendor', $storeSales);
        self::assertSame(1, substr_count($storeSales, 'Clear consignor'));
        self::assertSame(0, substr_count($storeSales, 'Clear vendor'));
    }

    public function testTheDashboardSeparatesJobsAndBooths(): void
    {
        $quiet = $this->render('Feature/Dashboard/dashboard.php', $this->dashboard(0, false));
        self::assertStringNotContainsString('A background job is waiting', $quiet);
        self::assertStringContainsString('Booth occupancy', $quiet);
        self::assertStringContainsString('Vendor payables', $quiet);

        $store = $this->render('Feature/Dashboard/dashboard.php', $this->dashboard(1, true));
        self::assertStringContainsString('A background job is waiting', $store);
        self::assertStringNotContainsString('Booth occupancy', $store);
        self::assertStringContainsString('Consignor payables', $store);
    }

    public function testConsignmentOnlyScreensDoNotSayVendor(): void
    {
        $expire = $this->render('Feature/Inventory/expire-desk.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'items' => [],
            'error' => null,
            'formToken' => 'token',
            'today' => '2026-06-01',
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('Return to the consignor', $expire);
        self::assertStringNotContainsString('Return to the vendor', $expire);

        $import = $this->render('Feature/Inventory/import.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'error' => null,
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('code, consignor, description', $import);
        self::assertStringNotContainsString('code, vendor, description', $import);

        $inventory = $this->render('Feature/Inventory/inventory.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'items' => [],
            'query' => '',
            'stock' => '',
            'total' => 1,
            'available' => 1,
            'unsold' => 0,
            'sold' => 0,
            'unprinted' => 0,
            'showBrand' => false,
            'confirm' => '',
            'error' => null,
            'attributeLists' => [],
            'labelStock' => '',
            'stocks' => [],
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('description, or consignor', $inventory);
        self::assertStringNotContainsString('description, or vendor', $inventory);

        $form = $this->render('Feature/Inventory/inventory-form.php', $this->inventoryForm(true));
        self::assertStringContainsString('<label for="vendor_label">Consignor</label>', $form);
        self::assertStringContainsString('Choose a consignor from the list.', $form);
        self::assertStringNotContainsString('Choose a vendor from the list.', $form);

        $mallForm = $this->render('Feature/Inventory/inventory-form.php', $this->inventoryForm(false));
        self::assertStringContainsString('<label for="vendor_label">Vendor</label>', $mallForm);
        self::assertStringContainsString('Choose a vendor from the list.', $mallForm);

        $customer = $this->render('Feature/Pos/customer-form.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'customer' => ['id' => 'c1', 'name' => 'Ada', 'phone' => '', 'email' => ''],
            'error' => null,
            'sellerList' => '/demo/consignors',
            'sellerRoleAction' => '',
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('Add consignor role', $customer);
        self::assertStringContainsString('consignors-role', $customer);
        self::assertStringNotContainsString('vendor-role', $customer);

        $memo = $this->render('Feature/Book/vendor-memo.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'vendors' => [],
            'error' => null,
            'owed' => null,
            'vendorId' => '',
            'direction' => '',
            'amount' => '',
            'reason' => '',
            'postingDate' => '',
            'today' => '2026-06-01',
            'formToken' => 'token',
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('<h1>Consignor memo</h1>', $memo);
        self::assertStringNotContainsString('<h1>Vendor memo</h1>', $memo);

        $offer = $this->render('Feature/Offer/offer-form.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'base' => '/demo/offers',
            'id' => '',
            'draft' => [
                'name' => '',
                'scope' => 'vendor',
                'kind' => 'percent',
                'threshold' => '',
                'price' => '',
                'funded_by' => 'store',
                'starts_on' => '',
                'ends_on' => '',
                'item_code' => '',
                'vendor_id' => '',
            ],
            'vendors' => [],
            'error' => null,
            'locked' => false,
            'consignmentOnly' => true,
        ]);
        self::assertStringContainsString('One consignor', $offer);
        self::assertStringNotContainsString('One vendor', $offer);
    }

    public function testTheVendorHomeShowsASaleWithoutTheOtherBalance(): void
    {
        $sold = $this->render('Feature/Vendor/home.php', ['slug' => 'demo', 'page' => $this->home(1, 0, 1, 0)]);
        self::assertStringContainsString('Consignment:', $sold);
        self::assertStringContainsString('Booth:', $sold);

        $quiet = $this->render('Feature/Vendor/home.php', ['slug' => 'demo', 'page' => $this->home(0, 0, 0, 0)]);
        self::assertStringNotContainsString('Consignment:', $quiet);
        self::assertStringNotContainsString('Booth:', $quiet);

        $expired = $this->render('Feature/Vendor/home.php', ['slug' => 'demo', 'page' => $this->home(0, 2, 0, 0)]);
        self::assertStringContainsString('Consignment:', $expired);
        self::assertStringNotContainsString('Booth:', $expired);

        $rent = $this->render('Feature/Vendor/home.php', ['slug' => 'demo', 'page' => $this->home(0, 0, 0, 300)]);
        self::assertStringNotContainsString('Consignment:', $rent);
        self::assertStringContainsString('Booth:', $rent);
    }

    public function testSettingsKeepTheCheckedMailboxAndTheFloorDays(): void
    {
        $onMoney = $this->render('Feature/Mall/settings.php', $this->settings('both', true, true, 'gmail', true, 'money'));
        self::assertStringContainsString('id="store_stock_enabled" name="store_stock_enabled" type="checkbox" value="1" checked', $onMoney);
        $onBuilding = $this->render('Feature/Mall/settings.php', $this->settings('both', true, true, 'gmail', true, 'building'));
        self::assertStringContainsString('id="floor_days"', $onBuilding);
        $onReceipt = $this->render('Feature/Mall/settings.php', $this->settings('both', true, true, 'gmail', true, 'receipt'));
        self::assertStringContainsString('value="gmail" selected', $onReceipt);
        self::assertStringNotContainsString('value="server" selected', $onReceipt);
        self::assertStringContainsString('An app password is saved', $onReceipt);

        $offMoney = $this->render('Feature/Mall/settings.php', $this->settings('booth_mall', false, true, 'server', false, 'money'));
        self::assertStringNotContainsString('checked', $offMoney);
        $offBuilding = $this->render('Feature/Mall/settings.php', $this->settings('booth_mall', false, true, 'server', false, 'building'));
        self::assertStringNotContainsString('id="floor_days"', $offBuilding);
        $offReceipt = $this->render('Feature/Mall/settings.php', $this->settings('booth_mall', false, true, 'server', false, 'receipt'));
        self::assertStringContainsString('value="server" selected', $offReceipt);
        self::assertStringContainsString('Create an app password', $offReceipt);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $relative, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__, 3) . '/src/' . $relative;
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function payout(bool $consignmentOnly): array
    {
        return [
            'slug' => 'demo',
            'csrf' => 'token',
            'vendors' => [
                ['id' => 'v1', 'code' => 'A1', 'name' => 'Ada'],
                ['id' => 'v2', 'code' => 'B2', 'name' => 'Bea'],
            ],
            'vendorId' => '',
            'open' => ['payable' => 100, 'rent' => 0, 'check' => 100, 'owes' => 0, 'settled' => 0, 'rent_from_sales' => false],
            'runId' => '',
            'undone' => null,
            'error' => null,
            'consignmentOnly' => $consignmentOnly,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(bool $consignmentOnly): array
    {
        return [
            'slug' => 'demo',
            'csrf' => 'token',
            'displayName' => 'Demo',
            'monthName' => 'October',
            'pack' => [[
                'vendor_id' => 'v1',
                'code' => 'A1',
                'name' => 'Ada',
                'booth' => '12',
                'boothId' => 'b1',
                'payable' => 100,
                'monthRent' => 75,
                'check' => 50,
                'owes' => 125,
            ]],
            'excluded' => [],
            'savedRun' => '',
            'savedTender' => '',
            'savedStatus' => '',
            'error' => null,
            'consignmentOnly' => $consignmentOnly,
        ];
    }

    private function register(bool $consignmentOnly, int $remaining, string $buyerId = ''): string
    {
        return $this->render('Feature/Pos/pos.php', [
            'slug' => 'demo',
            'csrf' => 'token',
            'templateDirectory' => dirname(__DIR__, 3) . '/src',
            'displayName' => 'Demo',
            'activeRegister' => 'Front',
            'chooseRegister' => false,
            'attributeLists' => [],
            'showBrand' => false,
            'consignmentOnly' => $consignmentOnly,
            'cart' => [
                'ticket_id' => 'ticket-1',
                'posting_key' => 'sale',
                'lines' => [],
                'merchandise' => 0,
                'discount' => 0,
                'discount_reason' => '',
                'tax' => 0,
                'tax_bps' => 0,
                'waiver' => '',
                'due' => $remaining,
                'cash_round' => 0,
                'cash_due' => $remaining,
                'rounding' => '5',
                'buyer_id' => $buyerId,
                'buyer_name' => '',
                'parked' => [],
            ],
            'tenders' => [],
            'deposits' => 0,
            'payments' => [],
            'remaining' => $remaining,
            'giftNumber' => '',
            'giftBalance' => null,
            'completed' => null,
            'change' => null,
            'openInstant' => true,
            'openGift' => false,
            'giftError' => '',
            'giftFormNumber' => '',
            'giftFormAmount' => '',
            'giftFormTender' => '',
            'giftFormCheck' => '',
            'giftCustom' => false,
            'openPayment' => true,
            'payError' => '',
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
            'notice' => null,
            'error' => null,
            'salesAvailable' => 0,
            'instantVendorLabel' => '',
            'instantVendorId' => '',
            'instantDescription' => '',
            'instantPrice' => '',
            'instantDiscountKind' => 'none',
            'instantDiscountValue' => '',
            'instantPriceError' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(int $overdueJobs, bool $consignmentOnly): array
    {
        return [
            'slug' => 'demo',
            'displayName' => 'Demo',
            'greeting' => 'Hello',
            'picture' => [
                'sales' => ['amount' => '$0.00', 'delta' => null],
                'payables' => ['amount' => '$0.00', 'caption' => 'open'],
                'trend' => [],
                'today_badge' => 'Today',
                'sales_list' => [],
                'occupancy' => ['percent' => 0, 'caption' => 'empty', 'rows' => []],
                'shelf' => ['value' => '$0.00', 'unsold' => 0],
            ],
            'rentRows' => [],
            'rentMonth' => '2026-10',
            'activeSessionId' => '',
            'overdueJobs' => $overdueJobs,
            'consignmentOnly' => $consignmentOnly,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function home(int $sold, int $expired, int $dealerSold, int $rent): array
    {
        return [
            'name' => 'Ada',
            'hold' => false,
            'open' => false,
            'payable' => 0,
            'rent_label' => 'Rent you owe',
            'rent' => 0,
            'check' => 0,
            'desk' => 0,
            'booth' => null,
            'others' => [],
            'activity' => [],
            'last' => null,
            'consignor' => ['sold' => $sold, 'take' => 0, 'owed' => 0, 'expired' => $expired],
            'dealer' => ['sold' => $dealerSold, 'owed' => 0, 'rent' => $rent, 'settled' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryForm(bool $consignmentOnly): array
    {
        return [
            'slug' => 'demo',
            'csrf' => 'token',
            'floorDays' => '30',
            'vendorsUrl' => '/demo/inventory/consignors.json',
            'brandsUrl' => '/demo/inventory/brands.json',
            'brandCreateUrl' => '/demo/inventory/brands',
            'sold' => false,
            'item' => [
                'id' => '',
                'code' => '',
                'barcode' => '',
                'description' => '',
                'price' => '',
                'quantity' => '1',
                'vendor_id' => '',
                'vendor_label' => '',
                'brand_id' => '',
                'brand_label' => '',
                'attributes' => [],
                'store_take' => '50',
                'default_take' => '50',
                'overridden' => false,
                'intake_on' => '2026-01-01',
                'expires_on' => '',
                'expire_dirty' => false,
            ],
            'attributeLists' => [],
            'labelStock' => '',
            'printId' => '',
            'stocks' => [],
            'hasPhoto' => false,
            'photoRule' => 'JPEG only.',
            'error' => null,
            'showBrand' => false,
            'consignmentOnly' => $consignmentOnly,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(string $mode, bool $stock, bool $consignment, string $via, bool $saved, string $part = 'money'): array
    {
        return [
            'slug' => 'demo',
            'csrf' => 'token',
            'settingsPart' => $part,
            'timeZone' => 'UTC',
            'timeZones' => ['UTC'],
            'boothWidth' => 10,
            'boothDepth' => 10,
            'floorWidth' => 40,
            'floorDepth' => 40,
            'storeTake' => '50',
            'floorDays' => '30',
            'cashRound' => '0',
            'taxRate' => '0',
            'backupHeadline' => '',
            'nightlyHeadline' => '',
            'receiptFooter' => '',
            'catalogs' => [],
            'stocks' => [],
            'labelStock' => '',
            'operatingMode' => $mode,
            'cycleDay' => 1,
            'cycleFrozen' => false,
            'storeStockEnabled' => $stock,
            'consignmentEnabled' => $consignment,
            'receiptMailVia' => $via,
            'gmailPasswordSaved' => $saved,
            'boothRentals' => true,
            'rentPrepaid' => false,
        ];
    }
}
