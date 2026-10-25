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
        $on = $this->render('Feature/Mall/settings.php', $this->settings('both', true, true, 'gmail', true));
        self::assertStringContainsString('id="store_stock_enabled" name="store_stock_enabled" type="checkbox" value="1" checked', $on);
        self::assertStringContainsString('id="floor_days"', $on);
        self::assertStringContainsString('value="gmail" selected', $on);
        self::assertStringNotContainsString('value="server" selected', $on);
        self::assertStringContainsString('An app password is saved', $on);

        $off = $this->render('Feature/Mall/settings.php', $this->settings('booth_mall', false, true, 'server', false));
        self::assertStringNotContainsString('checked', $off);
        self::assertStringNotContainsString('id="floor_days"', $off);
        self::assertStringContainsString('value="server" selected', $off);
        self::assertStringContainsString('Create an app password', $off);
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
                'rounding' => '1',
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
    private function settings(string $mode, bool $stock, bool $consignment, string $via, bool $saved): array
    {
        return [
            'slug' => 'demo',
            'csrf' => 'token',
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
