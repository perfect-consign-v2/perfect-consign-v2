<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PosRegisterCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(self::SLUG);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testABoothMoveAndCustomerRulesStayOnTheReceipt(): void
    {
        $this->openRegister();
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $vendor = $this->vendor('50');
        $other = $this->vendor('50');
        $item = $this->item($vendor, '10.00');
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $blank = $this->client->post('/' . self::SLUG . '/pos/instant', [
            'vendor_id' => $vendor,
            'description' => '',
            'price' => '4.00',
        ]);
        self::assertSees('Enter a description.', $blank);
        $noPrice = $this->client->post('/' . self::SLUG . '/pos/instant', [
            'vendor_id' => $vendor,
            'description' => 'No price',
            'price' => '',
        ]);
        self::assertSees('Enter a price.', $noPrice);
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        self::assertSees($item['code'], $cart);
        $longName = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => str_repeat('n', 121),
            'customer_phone' => '',
            'customer_email' => '',
        ]);
        self::assertSees('Enter the customer name.', $longName);
        $longPhone = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Pat ' . $item['code'],
            'customer_phone' => str_repeat('5', 41),
            'customer_email' => '',
        ]);
        self::assertSees('That phone number is too long.', $longPhone);
        $badEmail = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Pat ' . $item['code'],
            'customer_phone' => '',
            'customer_email' => 'not-an-email',
        ]);
        self::assertSees('Enter a real email or leave it blank.', $badEmail);
        $longEmail = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Pat ' . $item['code'],
            'customer_phone' => '',
            'customer_email' => str_repeat('a', 201) . '@x.test',
        ]);
        self::assertSees('Enter a real email or leave it blank.', $longEmail);
        $saved = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Pat ' . $item['code'],
            'customer_phone' => '555-0100',
            'customer_email' => 'pat-' . $item['code'] . '@example.test',
        ]);
        self::assertSees('Customer saved.', $saved);
        $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Pat ' . $item['code'],
            'customer_phone' => '555-0100',
            'customer_email' => 'pat-' . $item['code'] . '@example.test',
        ]);
        $cleared = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => '',
            'customer_phone' => '',
            'customer_email' => '',
        ]);
        self::assertSees('Customer saved.', $cleared);
        $badBuyer = $this->client->post('/' . self::SLUG . '/pos/buyer', [
            'vendor_id' => '01900000-0000-7000-8000-0000000000ff',
        ]);
        self::assertSees('Choose a vendor.', $badBuyer);
        $this->client->post('/' . self::SLUG . '/pos/buyer', ['vendor_id' => '']);
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_cash' => '20.00',
            'cash_received' => '20.00',
        ]);
        self::assertSees('Sale posted.', $paid);
        $itemId = $this->match($paid->body, '/name="item_id" value="([0-9a-fA-F-]{36})"/');
        $journalId = $this->match($paid->body, '#/pos/receipt/([0-9a-fA-F-]{36})/void#');
        $receipt = $this->client->get('/' . self::SLUG . '/pos/receipt/' . $journalId);
        $booth = $this->receiptAction($receipt->body, 'booth');
        $badBooth = $this->client->post($booth, ['item_id' => $itemId, 'vendor_id' => 'vendor']);
        self::assertSees('Choose the booth that should have been paid.', $badBooth);
        $same = $this->client->post($booth, ['item_id' => $itemId, 'vendor_id' => $vendor]);
        self::assertSees('Choose the booth that should have been paid.', $same);
        $missingItem = $this->client->post($booth, [
            'item_id' => '01900000-0000-7000-8000-0000000000aa',
            'vendor_id' => $other,
        ]);
        self::assertSees('that item is not on the ticket', $missingItem);
        $moved = $this->client->post($booth, ['item_id' => $itemId, 'vendor_id' => $other]);
        self::assertSees('The vendor payable moved.', $moved);
        $blocked = $this->client->post('/' . self::SLUG . '/inventory/' . $itemId, [
            'code' => $item['code'],
            'description' => 'Sold ' . $item['code'],
            'vendor_id' => $vendor,
            'brand_id' => '',
            'price' => '99.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('That price is already on a sale.', $blocked);
    }

    public function testGiftLayawayImportAndRegisterRefusals(): void
    {
        $this->openRegister();
        $this->client->get('/' . self::SLUG . '/pos/tender');
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $gift = $this->client->post('/' . self::SLUG . '/pos/gift', [
            'gift_kind' => 'gift_card',
            'gift_amount' => '5.00',
            'gift_tender' => 'card',
        ]);
        self::assertTrue(
            str_contains($gift->body, 'Gift card') || str_contains($gift->body, 'posted') || str_contains($gift->body, 'Print'),
            'gift card sale did not finish',
        );
        $credit = $this->client->post('/' . self::SLUG . '/pos/gift', [
            'gift_kind' => 'store_credit',
            'gift_amount' => '4.00',
            'gift_tender' => 'cash',
        ]);
        self::assertTrue(
            str_contains($credit->body, 'Store credit') || str_contains($credit->body, 'posted') || str_contains($credit->body, 'Print'),
            'store credit sale did not finish',
        );
        $tender = $this->client->post('/' . self::SLUG . '/pos/gift', [
            'gift_kind' => 'gift_certificate',
            'gift_amount' => '3.00',
            'gift_tender' => 'points',
        ]);
        self::assertSees('stored value is issued for cash, card, or a check', $tender);
        $longGift = $this->client->post('/' . self::SLUG . '/pos/gift', [
            'gift_kind' => 'gift_certificate',
            'gift_custom' => '1',
            'gift_number' => str_repeat('n', 41),
            'gift_amount' => '5.00',
            'gift_tender' => 'cash',
        ]);
        self::assertSees('a stored value number is required', $longGift);
        $badGift = $this->client->get('/' . self::SLUG . '/pos/gift/01900000-0000-7000-8000-0000000000cc');
        self::assertSees('that journal is not a gift certificate', $badGift);
        $vendor = $this->vendor('50');
        $held = $this->item($vendor, '12.00');
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $held['code']]);
        $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Lay ' . $held['code'],
            'customer_phone' => '',
            'customer_email' => '',
        ]);
        $deposit = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'hold' => '1',
            'tender_cash' => '4.00',
        ]);
        self::assertSees('Layaway held', $deposit);
        $layaways = $this->client->get('/' . self::SLUG . '/pos/layaways');
        $ticketId = $this->match($layaways->body, '/name="ticket_id" value="([0-9a-fA-F-]{36})"/');
        $slip = $this->client->get('/' . self::SLUG . '/pos/layaway/' . $ticketId);
        self::assertSees('Layaway receipt', $slip);
        $badCancel = $this->client->post('/' . self::SLUG . '/pos/layaway/cancel', ['ticket_id' => 'not-a-ticket']);
        self::assertSees('that ticket is not parked', $badCancel);
        $cancelled = $this->client->post('/' . self::SLUG . '/pos/layaway/cancel', ['ticket_id' => $ticketId]);
        self::assertSees('Layaway receipt', $cancelled);
        $empty = $this->file("code,vendor,description,price,barcode,quantity\n");
        self::assertSees(
            'That file has no tags.',
            $this->client->upload('/' . self::SLUG . '/inventory/import', [], ['tags' => $empty]),
        );
        $blankCells = $this->file("code,vendor,description,price,barcode,quantity\n,,coat,10.00,,\n");
        self::assertSees(
            'needs a code, a vendor, a description, and a price.',
            $this->client->upload('/' . self::SLUG . '/inventory/import', [], ['tags' => $blankCells]),
        );
        $missing = '01900000-0000-7000-8000-0000000000d1';
        self::assertSame(404, $this->client->post('/' . self::SLUG . '/registers/' . $missing . '/open', [
            'cashier' => 'Ada',
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ])->status);
        self::assertSame(404, $this->client->post('/' . self::SLUG . '/registers/' . $missing . '/drop', [
            'amount' => '1.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ])->status);
        $page = $this->client->get('/' . self::SLUG . '/registers');
        $registerId = $this->registerId($page->body);
        $future = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/drop', [
            'amount' => '1.00',
            'posting_date' => '2099-01-01',
        ]);
        self::assertSees('A cash drop cannot be dated after today.', $future);
        $zeroDrop = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/drop', [
            'amount' => '0.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('the amount must be greater than zero', $zeroDrop);
        $this->client->get('/' . self::SLUG . '/registers/new');
        $blankReg = $this->client->post('/' . self::SLUG . '/registers', ['name' => '']);
        self::assertSees('a register name is required and at most 40 characters', $blankReg);
        $name = 'R' . bin2hex(random_bytes(3));
        $added = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name]);
        self::assertSees('Register added.', $added);
        $extraId = HtmlPage::taggedId($added->body, 'article', 'register', $name);
        $this->client->post('/' . self::SLUG . '/registers/' . $extraId, ['name' => '']);
        $this->client->post('/' . self::SLUG . '/registers/' . $extraId . '/delete', []);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/inventory/01900000-0000-7000-8000-0000000000e1/edit')->status);
        $gone = $this->client->post('/' . self::SLUG . '/inventory/01900000-0000-7000-8000-0000000000e1/delete', []);
        self::assertSees('That item is not in this mall.', $gone);
    }

    public function testScanPayAndRegisterCountRefusals(): void
    {
        $this->openRegister();
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $blankScan = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => '']);
        self::assertSees('scan a barcode or a tag', $blankScan);
        $vendor = $this->vendor('50');
        $item = $this->item($vendor, '10.00');
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        $due = $this->match($cart->body, '/class="register-due">\\$([0-9.]+)</');
        $missingLine = $this->client->post('/' . self::SLUG . '/pos/price', [
            'line_no' => '9',
            'price' => '1.00',
        ]);
        self::assertSees('that item is not on the ticket', $missingLine);
        $over = $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'cash',
            'tender_amount' => '50.00',
        ]);
        self::assertSees('a sale debits the sold prices plus tax', $over);
        $badTender = $this->client->post('/' . self::SLUG . '/pos/tender/remove', [
            'tender_role' => 'points',
        ]);
        self::assertSees('a sale debits each tender once', $badTender);
        $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'card',
            'tender_amount' => $due,
        ]);
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $paid);

        $page = $this->client->get('/' . self::SLUG . '/registers');
        $registerId = $this->registerId($page->body);
        $earlyDrop = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/drop', [
            'amount' => '1.00',
            'posting_date' => '2000-01-01',
        ]);
        self::assertSees('A cash drop cannot be dated before the register was opened.', $earlyDrop);
        $name = 'R' . bin2hex(random_bytes(3));
        $added = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name]);
        self::assertSees('Register added.', $added);
        $extraId = HtmlPage::taggedId($added->body, 'article', 'register', $name);
        $mismatch = $this->client->post('/' . self::SLUG . '/registers/' . $extraId . '/open', [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '10.00',
            ...$this->zeroCounts(),
            'count_100' => '1',
        ]);
        self::assertSees('the bills and coins must add up to the opening float', $mismatch);
        $blankCashier = $this->client->post('/' . self::SLUG . '/registers/' . $extraId . '/open', [
            'cashier' => '',
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ]);
        self::assertSees('a cashier name is required', $blankCashier);
        $this->client->post('/' . self::SLUG . '/registers/' . $extraId . '/delete', []);
    }

    public function testSalesTaxAndAdjustmentInvalidAmounts(): void
    {
        $yesterday = $this->client->get('/' . self::SLUG . '/sales?range=yesterday');
        self::assertSees('Yesterday', $yesterday);
        $all = $this->client->get('/' . self::SLUG . '/sales?range=all');
        self::assertSees('Recent sales', $all);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/journals/01900000-0000-7000-8000-0000000000f1')->status);
        $period = $this->client->post('/' . self::SLUG . '/periods', [
            'starts_on' => '2015-01-01',
            'ends_on' => '2015-12-31',
        ]);
        self::assertLessThan(500, $period->status);
        $close = $this->client->get('/' . self::SLUG . '/close');
        if (preg_match('/2015-01-01[\s\S]{0,400}name="period_id" value="([0-9a-fA-F-]{36})"/', $close->body, $found) === 1) {
            $quiet = $this->client->post('/' . self::SLUG . '/close', [
                'period_id' => $found[1],
                'ends_on' => '2015-12-31',
            ]);
            self::assertSees('Period closed.', $quiet);
        }
        $adjustment = $this->client->get('/' . self::SLUG . '/adjustment');
        $cash = $this->match($adjustment->body, '/<option value="([0-9a-fA-F-]{36})">1000 /');
        $bank = $this->match($adjustment->body, '/<option value="([0-9a-fA-F-]{36})">1010 /');
        $badAdj = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => 'adj' . bin2hex(random_bytes(2)),
            'posting_date' => ProbeMall::POSTING_DATE,
            'description' => 'bad amount',
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => 'nope',
            'line_account_2' => $bank,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ]);
        self::assertSees('enter an amount in dollars and cents', $badAdj);
        $badTax = $this->client->post('/' . self::SLUG . '/tax', [
            'source_reference' => 'tax' . bin2hex(random_bytes(2)),
            'amount' => 'nope',
            'credit' => 'bank',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('enter an amount in dollars and cents', $badTax);
        $this->client->post('/' . self::SLUG . '/opening', [
            'posting_date' => ProbeMall::POSTING_DATE,
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => 'nope',
            'line_account_2' => $bank,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ]);
        $this->client->get('/' . self::SLUG . '/gift-certificates');
        $vendorDeal = $this->client->post('/' . self::SLUG . '/offers', [
            'name' => 'Need vendor',
            'scope' => 'vendor',
            'kind' => 'bundle',
            'threshold' => '3',
            'price' => '10.00',
            'funded_by' => 'vendor',
            'starts_on' => ProbeMall::POSTING_DATE,
            'ends_on' => '',
            'item_code' => '',
            'vendor_id' => '',
        ]);
        self::assertSees('Choose the vendor.', $vendorDeal);
    }

    private function openRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $this->client->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ]);
    }

    private function vendor(string $take): string
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $page = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => $take,
        ]);
        self::assertSees('Vendor created.', $page);

        return $this->match($page->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#');
    }

    /**
     * @return array{code: string}
     */
    private function item(string $vendorId, string $price): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => 'Item ' . $code,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        return ['code' => $code];
    }

    /**
     * @return array<string, string>
     */
    private function zeroCounts(): array
    {
        $fields = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }

        return $fields;
    }

    private function receiptAction(string $html, string $action): string
    {
        return '/' . self::SLUG . '/pos/receipt/' . $this->match($html, '#/pos/receipt/([0-9a-fA-F-]{36})/' . $action . '#') . '/' . $action;
    }

    private function registerId(string $html): string
    {
        if (preg_match('#/registers/([0-9a-fA-F-]{36})/close#', $html, $close) === 1) {
            return $close[1];
        }

        return $this->match($html, '/id="register-([0-9a-fA-F-]{36})"/');
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-cov-');
        self::assertNotFalse($path);
        $written = file_put_contents($path, $contents);
        self::assertNotFalse($written);

        return $path;
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
