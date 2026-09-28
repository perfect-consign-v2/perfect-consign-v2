<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('functional')]
final class OwnerJourneyTest extends WebTestCase
{
    private FormClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(ProbeMall::SLUG);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
        self::assertSees('Inventory value', $signedIn);
        self::assertSees('Low stock', $signedIn);
    }

    public function testTheDashboardKeepsInventoryCardsEmpty(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/dashboard');

        self::assertSame(200, $page->status);
        self::assertSees('Sales trend', $page);
        self::assertSees('Booth occupancy', $page);
        self::assertSees('Vendor payables', $page);
        self::assertDoesNotMatchRegularExpression('/Inventory value<\\/div>\\s*<div class="stat-value">\\$/', $page->body);
    }

    public function testTheOwnerPostsASaleAndSeesTheJournal(): void
    {
        $this->openRegister();
        $this->roundCash(true);
        $vendor = $this->vendor();
        $directory = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        $vendorId = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $vendor);
        $tag = 't' . bin2hex(random_bytes(4));
        $description = 'Coat ' . $tag;
        $created = $this->client->post('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '10.02',
            'barcode' => 'BC-' . $tag,
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Piece created.', $created);
        $this->client->post('/' . ProbeMall::SLUG . '/pos/discard', []);
        $cart = $this->client->post('/' . ProbeMall::SLUG . '/pos/scan', ['scan' => 'BC-' . $tag]);
        self::assertSees($description, $cart);
        self::assertSees('id="cash-round">-0.02', $cart);
        self::assertSame(1, preg_match('/name="line_no" value="(\d+)"/', $cart->body, $line));
        $again = $this->client->post('/' . ProbeMall::SLUG . '/pos/scan', ['scan' => 'BC-' . $tag]);
        self::assertSees('a piece is already on the ticket', $again);
        $priced = $this->client->post('/' . ProbeMall::SLUG . '/pos/price', [
            'line_no' => $line[1],
            'price' => '10.03',
        ]);
        self::assertSees('id="amount-due">10.03', $priced);
        self::assertSees('id="cash-round">0.02', $priced);
        $paid = $this->client->post('/' . ProbeMall::SLUG . '/pos/pay', [
            'tender_cash' => '10.03',
            'cash_received' => '20.00',
        ]);
        self::assertSees($description, $paid);
        self::assertSees('id="cash-round">0.02', $paid);
        self::assertSees('id="change">9.95', $paid);
        self::assertSame(1, preg_match('/sale:[0-9a-f-]{36}/', $paid->body, $key));
        self::assertSees($key[0], $this->client->get('/' . ProbeMall::SLUG . '/journals'));
        self::assertSame(1, preg_match('#/journals/([0-9a-f-]{36})#', $paid->body, $journalId));
        $journal = $this->client->get('/' . ProbeMall::SLUG . '/journals/' . $journalId[1]);
        self::assertSees('<p>Sale</p>', $journal);
        $this->roundCash(false);
    }

    public function testTheOwnerPostsRentAndSeesTheCharge(): void
    {
        $vendor = $this->vendor();
        $booth = $this->booth();
        $parties = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        $booths = $this->client->get('/' . ProbeMall::SLUG . '/booths');
        $vendorId = HtmlPage::taggedId($parties->body, 'tr', 'vendor', $vendor);
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $booth);
        $this->client->post('/' . ProbeMall::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $this->client->post('/' . ProbeMall::SLUG . '/rates', [
            'booth_id' => $boothId,
            'month_start' => ProbeMall::POSTING_DATE,
            'amount' => '100.00',
        ]);
        $journal = $this->client->post('/' . ProbeMall::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSame(200, $journal->status);
        self::assertSees('Rent charge', $journal);
    }

    public function testAPayoutWithNothingOpenIsRefused(): void
    {
        $code = $this->vendor();
        $parties = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        $vendorId = HtmlPage::taggedId($parties->body, 'tr', 'vendor', $code);
        $form = $this->client->get('/' . ProbeMall::SLUG . '/payout?vendor=' . $vendorId);
        self::assertSees('Open payable 0.00', $form);
        $refused = $this->client->post('/' . ProbeMall::SLUG . '/payout', [
            'vendor_id' => $vendorId,
            'month' => ProbeMall::MONTH,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSame(200, $refused->status);
        self::assertSees('a payout clears a positive open payable or open rent receivable', $refused);
    }

    public function testTheOwnerAddsRenamesAndDeletesABrand(): void
    {
        $name = 'Brand ' . bin2hex(random_bytes(4));
        $renamed = $name . ' renamed';
        $page = $this->client->post('/' . ProbeMall::SLUG . '/brands', [
            'name' => $name,
        ]);
        self::assertSees($name, $page);
        $brandId = HtmlPage::taggedId($page->body, 'tr', 'brand', $name);
        $update = '/' . ProbeMall::SLUG . '/brands/' . $brandId;
        $renamedPage = $this->client->post($update, [
            'name' => $renamed,
        ]);
        self::assertSees($renamed, $renamedPage);
        $deleted = $this->client->post($update . '/delete', []);
        self::assertStringNotContainsString($renamed, $deleted->body);
        $added = $this->client->post('/' . ProbeMall::SLUG . '/brands', [
            'name' => $name,
        ]);
        self::assertSees($name, $added);
        $duplicate = $this->client->post('/' . ProbeMall::SLUG . '/brands', [
            'name' => $name,
        ]);

        self::assertSame(200, $duplicate->status);
        self::assertSees('That name is already used.', $duplicate);
    }

    public function testTheOwnerAddsEditsAndDeletesAnItem(): void
    {
        $vendor = $this->vendor();
        $directory = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        $vendorId = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $vendor);
        $brand = 'Brand ' . bin2hex(random_bytes(4));
        $other = $brand . ' other';
        $this->client->post('/' . ProbeMall::SLUG . '/brands', ['name' => $brand]);
        $this->client->post('/' . ProbeMall::SLUG . '/brands', ['name' => $other]);
        $brands = $this->client->get('/' . ProbeMall::SLUG . '/brands');
        $brandId = HtmlPage::taggedId($brands->body, 'tr', 'brand', $brand);
        $otherId = HtmlPage::taggedId($brands->body, 'tr', 'brand', $other);
        $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
            'floor_days' => '30',
        ]);
        $tag = 't' . bin2hex(random_bytes(4));
        $created = 'Blue ' . $tag;
        $renamed = 'Green ' . $tag;
        $barcode = 'CUST-' . $tag;
        $label = '#' . $vendor . " \u{2014} Ada " . $vendor;
        $page = $this->client->post('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $created,
            'vendor_id' => $vendorId,
            'brand_id' => $brandId,
            'price' => '12.50',
            'barcode' => $barcode,
            'store_take' => '15',
            'intake_on' => '2026-09-01',
            'expires_on' => '2026-12-01',
        ]);
        self::assertSees('Piece created.', $page);
        self::assertSees('Adding pieces for ' . $label, $page);
        self::assertSees('value="2026-09-01"', $page);
        self::assertSees('value="2026-10-01"', $page);
        self::assertStringNotContainsString('2026-12-01', $page->body);
        self::assertStringNotContainsString($created, $page->body);
        $list = $this->client->get('/' . ProbeMall::SLUG . '/inventory');
        self::assertSees($tag, $list);
        self::assertSees($created, $list);
        self::assertSees($brand, $list);
        self::assertSees($barcode, $list);
        self::assertSees('2026-12-01', $list);
        self::assertSees('Overridden', $list);
        self::assertSees($label, $list);
        $itemId = HtmlPage::taggedId($list->body, 'tr', 'item', $tag);
        $update = '/' . ProbeMall::SLUG . '/inventory/' . $itemId;
        $edited = $this->client->post($update, [
            'code' => $tag,
            'description' => $renamed,
            'vendor_id' => $vendorId,
            'brand_id' => $otherId,
            'price' => '15.00',
            'barcode' => $barcode,
            'store_take' => '15',
            'intake_on' => '2026-09-01',
            'expires_on' => '2026-12-01',
        ]);
        self::assertSees($renamed, $edited);
        self::assertSees($other, $edited);
        self::assertSees('15.00', $edited);
        self::assertSees('Overridden', $edited);
        $deleted = $this->client->post($update . '/delete', []);
        self::assertStringNotContainsString($renamed, $deleted->body);
        $added = $this->client->post('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $created,
            'vendor_id' => $vendorId,
            'brand_id' => $brandId,
            'price' => '12.50',
            'barcode' => $barcode,
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Adding pieces for ' . $label, $added);
        $consignors = $this->client->get('/' . ProbeMall::SLUG . '/inventory/vendors.json?q=' . rawurlencode($vendor));
        self::assertSees($label, $consignors);
        $brandName = 'Intake ' . $tag;
        $made = $this->client->post('/' . ProbeMall::SLUG . '/inventory/brands', ['name' => $brandName]);
        self::assertSees($brandName, $made);
        $repeat = $this->client->post('/' . ProbeMall::SLUG . '/inventory/brands', ['name' => $brandName]);
        self::assertSees('That name is already used.', $repeat);
        $duplicate = $this->client->post('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => 'Another vase',
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '9.00',
            'barcode' => 'OTHER-' . $tag,
            'intake_on' => '2026-09-01',
        ]);

        self::assertSame(200, $duplicate->status);
        self::assertSees('That tag is already used.', $duplicate);
        self::assertSees($label, $duplicate);
        $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
            'floor_days' => '',
        ]);
    }

    public function testTheOwnerAddsEditsAndDeletesAWaitListPerson(): void
    {
        $last = bin2hex(random_bytes(4));
        $name = 'Ada ' . $last;
        $email = $last . '@example.com';
        $fields = [
            'first_name' => 'Ada',
            'last_name' => $last,
            'email' => $email,
            'phone' => '555-0100',
            'width_ft' => '10',
            'depth_ft' => '8',
        ];
        $page = $this->client->post('/' . ProbeMall::SLUG . '/wait-list', $fields);
        self::assertSees($name, $page);
        self::assertSees('Waiting', $page);
        self::assertSees('10 × 8 ft', $page);
        $id = HtmlPage::taggedId($page->body, 'article', 'wait', $name);
        $edited = $this->client->post('/' . ProbeMall::SLUG . '/wait-list/' . $id, $fields + [
            'status' => 'contacted',
            'notes' => 'Called Tuesday',
        ]);
        self::assertSees('Contacted', $edited);
        self::assertSees('Called Tuesday', $edited);
        $duplicate = $this->client->post('/' . ProbeMall::SLUG . '/wait-list', [
            'first_name' => 'Bea',
            'last_name' => $last,
            'email' => $email,
            'phone' => '555-0101',
        ]);
        self::assertSame(200, $duplicate->status);
        self::assertSees('That email is already on the wait list.', $duplicate);
        $deleted = $this->client->post('/' . ProbeMall::SLUG . '/wait-list/' . $id . '/delete', []);
        self::assertStringNotContainsString($name, $deleted->body);
    }

    public function testTheOwnerAddsRenamesAndDeletesABooth(): void
    {
        $code = 'b' . bin2hex(random_bytes(4));
        $renamed = $code . 'x';
        $page = $this->client->post('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => 'Front',
            'width_ft' => '',
            'depth_ft' => '',
        ]);
        self::assertSees($code, $page);
        self::assertSees('10 × 10 ft', $page);
        $boothId = HtmlPage::taggedId($page->body, 'tr', 'booth', $code);
        $update = '/' . ProbeMall::SLUG . '/booths/' . $boothId;
        $renamedPage = $this->client->post($update, [
            'code' => $renamed,
            'name' => 'Front',
            'width_ft' => '12',
            'depth_ft' => '8',
        ]);
        self::assertSees('12 × 8 ft', $renamedPage);
        $deleted = $this->client->post($update . '/delete', []);
        self::assertStringNotContainsString($renamed, $deleted->body);
        $added = $this->client->post('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => 'Front',
        ]);
        self::assertSees($code, $added);
        $duplicate = $this->client->post('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => 'Again',
        ]);

        self::assertSame(200, $duplicate->status);
        self::assertSees('booths_code_key', $duplicate);
    }

    public function testTheOwnerSetsTheMallClock(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/settings');
        self::assertSees('America/Los_Angeles', $page);
        self::assertSees('value="120"', $page);
        self::assertSees('value="90"', $page);
        self::assertSees('Days on the floor', $page);
        $refused = $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'Not/AZone',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
        ]);
        self::assertSame(200, $refused->status);
        self::assertSees('that time zone is not an IANA name', $refused);
        $days = $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
            'floor_days' => '0',
        ]);
        self::assertSame(200, $days->status);
        self::assertSees('days on the floor is a whole number from 1 to 365, or empty', $days);
        $saved = $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'America/Chicago',
            'booth_width_ft' => '12',
            'booth_depth_ft' => '8',
            'floor_width_ft' => '100',
            'floor_depth_ft' => '80',
            'default_store_take' => '0',
            'floor_days' => '45',
        ]);
        self::assertSees('America/Chicago', $saved);
        self::assertSees('value="45"', $saved);
        $restored = $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
        ]);
        self::assertSees('America/Los_Angeles', $restored);
    }

    public function testTheOwnerAddsAVendorAndAssignsABooth(): void
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $name = 'Ada ' . $code;
        $created = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'phone' => '555-0100',
            'email' => 'ada@example.com',
            'address' => '1 Main',
        ]);
        self::assertSees('Ada ' . $code, $created);
        self::assertSees('555-0100', $created);
        self::assertSees('ada@example.com', $created);
        self::assertSees('Active', $created);
        $directory = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        self::assertSees($name, $directory);
        $vendorId = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $code);
        $booth = $this->booth();
        $booths = $this->client->get('/' . ProbeMall::SLUG . '/booths');
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $booth);
        $assigned = $this->client->post('/' . ProbeMall::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);

        self::assertSame(200, $assigned->status);
        self::assertSees('Active', $assigned);
        self::assertSees($booth, $assigned);
        self::assertSees($name, $assigned);
    }

    public function testTheOwnerRecordsStoreTakeAgreementAndTaxExempt(): void
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $refused = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'tax_exempt' => '1',
        ]);
        self::assertSame(200, $refused->status);
        self::assertSees('Tax exempt number is required when tax exempt is enabled', $refused);
        $created = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'agreement_starts_on' => '2026-01-01',
            'agreement_ends_on' => '2026-12-31',
            'tax_id' => '12-3456789',
            'notes' => 'Booth near the door',
            'tax_exempt' => '1',
            'tax_exempt_number' => 'RESALE-100',
        ]);

        self::assertSees('0%', $created);
        self::assertSees('2026-01-01', $created);
        self::assertSees('2026-12-31', $created);
        self::assertSees('12-3456789', $created);
        self::assertSees('Booth near the door', $created);
        self::assertSees('RESALE-100', $created);
    }

    public function testTheMallSplitShowsOnTheVendor(): void
    {
        $settings = [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
        ];
        $saved = $this->client->post('/' . ProbeMall::SLUG . '/settings', $settings + ['default_store_take' => '30']);
        self::assertSees('value="30"', $saved);
        $code = 'v' . bin2hex(random_bytes(4));
        $created = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => '10',
        ]);
        self::assertSees('10%', $created);
        self::assertSees('Overridden', $created);
        $same = 'v' . bin2hex(random_bytes(4));
        $matched = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $same,
            'first_name' => 'Ada',
            'last_name' => $same,
            'store_take' => '30',
        ]);
        self::assertSees('30%', $matched);
        self::assertStringNotContainsString('Overridden', $matched->body);
        $restored = $this->client->post('/' . ProbeMall::SLUG . '/settings', $settings + ['default_store_take' => '0']);
        self::assertSees('value="0"', $restored);
    }

    public function testAVendorWithOpenRentCannotBeMadeInactive(): void
    {
        $vendor = $this->vendor();
        $booth = $this->booth();
        $parties = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        $booths = $this->client->get('/' . ProbeMall::SLUG . '/booths');
        $vendorId = HtmlPage::taggedId($parties->body, 'tr', 'vendor', $vendor);
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $booth);
        $this->client->post('/' . ProbeMall::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $this->client->post('/' . ProbeMall::SLUG . '/rates', [
            'booth_id' => $boothId,
            'month_start' => ProbeMall::POSTING_DATE,
            'amount' => '100.00',
        ]);
        $this->client->post('/' . ProbeMall::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        $refused = $this->client->post('/' . ProbeMall::SLUG . '/vendors/' . $vendorId . '/standing', [
            'standing' => 'inactive',
        ]);

        self::assertSame(200, $refused->status);
        self::assertSees('A vendor with open rent cannot be made inactive.', $refused);
        self::assertSees('Make inactive', $refused);
    }

    private function roundCash(bool $on): void
    {
        $fields = [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
            'floor_days' => '',
        ];
        if ($on) {
            $fields['cash_round_nickel'] = '1';
        }
        $saved = $this->client->post('/' . ProbeMall::SLUG . '/settings', $fields);
        self::assertSees('Settings saved.', $saved);
    }

    private function openRegister(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/registers');
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $pattern = '#(/' . ProbeMall::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#';
        if (preg_match($pattern, $page->body, $match) !== 1) {
            self::fail('the open register action was not on the page');
        }
        $fields = [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
        ];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        $opened = $this->client->post($match[1], $fields);
        self::assertSees('Close register', $opened);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $page = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }

    private function booth(): string
    {
        $code = 'b' . bin2hex(random_bytes(4));
        $page = $this->client->post('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }
}
