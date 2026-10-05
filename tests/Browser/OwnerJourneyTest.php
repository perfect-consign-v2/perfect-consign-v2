<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Browser;

use PerfectConsign\Tests\Support\ChromeProbe;
use PerfectConsign\Tests\Support\ChromeSession;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class OwnerJourneyTest extends WebTestCase
{
    private static ?ChromeProbe $chrome = null;

    private ChromeSession $session;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(ProbeMall::SLUG);
        self::$chrome = new ChromeProbe();
    }

    public static function tearDownAfterClass(): void
    {
        self::$chrome?->close();
        self::$chrome = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $chrome = self::$chrome;
        if (!$chrome instanceof ChromeProbe) {
            self::fail('the browser did not start');
        }
        $this->session = $chrome->open(self::$baseUrl);
    }

    protected function tearDown(): void
    {
        $this->session->close();
        parent::tearDown();
    }

    public function testTheOwnerSignsInAndSeesTheBook(): void
    {
        $book = $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);

        self::assertSees("Today's sales", $book);
    }

    public function testTheOwnerPostsASaleAndSeesItOnJournals(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->openRegister();
        $vendor = $this->vendor();
        $vendorId = HtmlPage::taggedId($this->session->open('/' . ProbeMall::SLUG . '/parties'), 'tr', 'vendor', $vendor);
        $tag = 't' . bin2hex(random_bytes(4));
        $description = 'Coat ' . $tag;
        $this->session->open('/' . ProbeMall::SLUG . '/inventory/new');
        $this->session->submit('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '10.00',
            'intake_on' => '2026-09-01',
        ]);
        $pos = $this->session->open('/' . ProbeMall::SLUG . '/pos');
        if (str_contains($pos, '/' . ProbeMall::SLUG . '/pos/discard')) {
            $this->session->submit('/' . ProbeMall::SLUG . '/pos/discard', []);
        }
        $cart = $this->session->submit('/' . ProbeMall::SLUG . '/pos/scan', ['scan' => $tag]);
        self::assertSees($description, $cart);
        $paid = $this->session->submit('/' . ProbeMall::SLUG . '/pos/pay', ['tender_cash' => '10.00']);
        self::assertSees($description, $paid);
        self::assertSame(1, preg_match('/sale:[0-9a-f-]{36}/', $paid, $key));
        self::assertSees($key[0], $this->session->open('/' . ProbeMall::SLUG . '/journals'));
        self::assertSame(1, preg_match('#/journals/([0-9a-f-]{36})#', $paid, $journalId));
        self::assertSees('<p>Sale</p>', $this->session->open('/' . ProbeMall::SLUG . '/journals/' . $journalId[1]));
    }

    public function testTheOwnerPostsRentAndSeesTheCharge(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $vendor = $this->vendor();
        $vendorId = HtmlPage::taggedId($this->session->html(), 'article', 'vendor', $vendor);
        $booth = $this->booth();
        $boothId = HtmlPage::taggedId($this->session->html(), 'tr', 'booth', $booth);
        $this->session->open('/' . ProbeMall::SLUG . '/vendors/' . $vendorId);
        $this->session->submit('/' . ProbeMall::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $this->session->open('/' . ProbeMall::SLUG . '/booths/' . $boothId . '/edit');
        $this->session->submit('/' . ProbeMall::SLUG . '/rates', [
            'booth_id' => $boothId,
            'month_start' => ProbeMall::POSTING_DATE,
            'amount' => '100.00',
        ]);
        $this->session->open('/' . ProbeMall::SLUG . '/rent');
        $book = $this->session->submit('/' . ProbeMall::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSees('Charged', $book);
        self::assertSame(1, preg_match(
            '#id="booth-' . preg_quote($boothId, '#') . '".*?/journals/([0-9a-fA-F-]{36})#s',
            $book,
            $journalId
        ));
        self::assertSees('Rent charge', $this->session->open('/' . ProbeMall::SLUG . '/journals/' . $journalId[1]));
    }

    public function testTheOwnerSeesARefusedPayout(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $code = $this->vendor();
        $vendorId = HtmlPage::taggedId($this->session->html(), 'article', 'vendor', $code);
        $form = $this->session->open('/' . ProbeMall::SLUG . '/payout?vendor=' . rawurlencode($vendorId));
        self::assertSees('Their share of sales $0.00', $form);
        $refused = $this->session->submit('/' . ProbeMall::SLUG . '/payout', [
            'vendor_id' => $vendorId,
        ]);

        self::assertSees('a payout clears a positive open payable or open rent receivable', $refused);
    }

    public function testTheOwnerAddsRenamesAndDeletesABrand(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->session->open('/' . ProbeMall::SLUG . '/brands/new');
        $name = 'Brand ' . bin2hex(random_bytes(4));
        $renamed = $name . ' renamed';
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/brands', [
            'name' => $name,
        ]);
        self::assertSees($name, $page);
        $brandId = HtmlPage::taggedId($page, 'tr', 'brand', $name);
        $edit = '/' . ProbeMall::SLUG . '/brands/' . $brandId . '/edit';
        $update = '/' . ProbeMall::SLUG . '/brands/' . $brandId;
        self::assertIsString($update);
        $this->session->open($edit);
        $renamedPage = $this->session->submit($update, [
            'name' => $renamed,
        ]);
        self::assertSees($renamed, $renamedPage);
        $this->session->open('/' . ProbeMall::SLUG . '/brands?confirm=' . $brandId);
        $deleted = $this->session->submit($update . '/delete', []);
        self::assertStringNotContainsString($renamed, $deleted);
        $this->session->open('/' . ProbeMall::SLUG . '/brands/new');
        $added = $this->session->submit('/' . ProbeMall::SLUG . '/brands', [
            'name' => $name,
        ]);
        self::assertSees($name, $added);
        $this->session->open('/' . ProbeMall::SLUG . '/brands/new');
        $duplicate = $this->session->submit('/' . ProbeMall::SLUG . '/brands', [
            'name' => $name,
        ]);

        self::assertSees('That name is already used.', $duplicate);
    }

    public function testTheOwnerAddsEditsAndDeletesAWaitListPerson(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
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
        $this->session->open('/' . ProbeMall::SLUG . '/wait-list/new');
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/wait-list', $fields);
        self::assertSees($name, $page);
        self::assertSees('Waiting', $page);
        self::assertSees('10 × 8 ft', $page);
        $id = HtmlPage::taggedId($page, 'article', 'wait', $name);
        $this->session->open('/' . ProbeMall::SLUG . '/wait-list/' . $id . '/edit');
        $edited = $this->session->submit('/' . ProbeMall::SLUG . '/wait-list/' . $id, $fields + [
            'status' => 'contacted',
            'notes' => 'Called Tuesday',
        ]);
        self::assertSees('Contacted', $edited);
        self::assertSees('Called Tuesday', $edited);
        $this->session->open('/' . ProbeMall::SLUG . '/wait-list/new');
        $duplicate = $this->session->submit('/' . ProbeMall::SLUG . '/wait-list', [
            'first_name' => 'Bea',
            'last_name' => $last,
            'email' => $email,
            'phone' => '555-0101',
        ]);
        self::assertSees('That email is already on the wait list.', $duplicate);
        $this->session->open('/' . ProbeMall::SLUG . '/wait-list?confirm=' . rawurlencode($id));
        $deleted = $this->session->submit('/' . ProbeMall::SLUG . '/wait-list/' . $id . '/delete', []);
        self::assertStringNotContainsString($name, $deleted);
    }

    public function testTheOwnerAddsEditsAndDeletesAnItem(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $vendor = $this->vendor();
        $directory = $this->session->open('/' . ProbeMall::SLUG . '/parties');
        $vendorId = HtmlPage::taggedId($directory, 'tr', 'vendor', $vendor);
        $brand = 'Brand ' . bin2hex(random_bytes(4));
        $other = $brand . ' other';
        $this->session->open('/' . ProbeMall::SLUG . '/brands/new');
        $this->session->submit('/' . ProbeMall::SLUG . '/brands', ['name' => $brand]);
        $this->session->open('/' . ProbeMall::SLUG . '/brands/new');
        $this->session->submit('/' . ProbeMall::SLUG . '/brands', ['name' => $other]);
        $brands = $this->session->open('/' . ProbeMall::SLUG . '/brands');
        $brandId = HtmlPage::taggedId($brands, 'tr', 'brand', $brand);
        $otherId = HtmlPage::taggedId($brands, 'tr', 'brand', $other);
        $tag = 't' . bin2hex(random_bytes(4));
        $created = 'Blue ' . $tag;
        $renamed = 'Green ' . $tag;
        $this->session->open('/' . ProbeMall::SLUG . '/inventory/new');
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $created,
            'vendor_id' => $vendorId,
            'brand_id' => $brandId,
            'price' => '12.50',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Piece created.', $page);
        self::assertSees('#' . $vendor, $page);
        self::assertSees('value="2026-09-01"', $page);
        $list = $this->session->open('/' . ProbeMall::SLUG . '/inventory');
        self::assertSees($created, $list);
        self::assertSees('12.50', $list);
        $itemId = HtmlPage::taggedId($list, 'tr', 'item', $tag);
        $edit = '/' . ProbeMall::SLUG . '/inventory/' . $itemId . '/edit';
        $update = '/' . ProbeMall::SLUG . '/inventory/' . $itemId;
        $this->session->open($edit);
        $edited = $this->session->submit($update, [
            'code' => $tag,
            'description' => $renamed,
            'vendor_id' => $vendorId,
            'brand_id' => $otherId,
            'price' => '15.00',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees($renamed, $edited);
        self::assertSees($other, $edited);
        self::assertSees('15.00', $edited);
        $this->session->open('/' . ProbeMall::SLUG . '/inventory?confirm=' . rawurlencode($itemId));
        $deleted = $this->session->submit($update . '/delete', []);
        self::assertStringNotContainsString($renamed, $deleted);
        $this->session->open('/' . ProbeMall::SLUG . '/inventory/new');
        $added = $this->session->submit('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $created,
            'vendor_id' => $vendorId,
            'brand_id' => $brandId,
            'price' => '12.50',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Piece created.', $added);
        self::assertSees('#' . $vendor, $added);
        $this->session->open('/' . ProbeMall::SLUG . '/inventory/new');
        $duplicate = $this->session->submit('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => 'Another vase',
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '9.00',
            'intake_on' => '2026-09-01',
        ]);

        self::assertSees('That tag is already used.', $duplicate);
    }

    public function testTheOwnerAddsRenamesAndDeletesABooth(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->session->open('/' . ProbeMall::SLUG . '/booths/new');
        $code = 'b' . bin2hex(random_bytes(4));
        $renamed = $code . 'x';
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => 'Front',
        ]);
        self::assertSees($code, $page);
        $boothId = HtmlPage::taggedId($page, 'tr', 'booth', $code);
        $edit = '/' . ProbeMall::SLUG . '/booths/' . $boothId . '/edit';
        $update = '/' . ProbeMall::SLUG . '/booths/' . $boothId;
        $this->session->open($edit);
        $renamedPage = $this->session->submit($update, [
            'code' => $renamed,
            'name' => 'Front',
            'width_ft' => '12',
            'depth_ft' => '8',
        ]);
        self::assertSees('12 × 8 ft', $renamedPage);
        $this->session->open('/' . ProbeMall::SLUG . '/booths?confirm=' . $boothId);
        $deleted = $this->session->submit($update . '/delete', []);
        self::assertStringNotContainsString($renamed, $deleted);
    }

    public function testTheOwnerSignsOut(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $confirm = $this->session->submit('/' . ProbeMall::SLUG . '/logout', []);
        self::assertSees('<h1>Sign out</h1>', $confirm);
        $home = $this->session->submit('/' . ProbeMall::SLUG . '/logout/confirm', []);

        self::assertSees('Sign in', $home);
    }

    private function openRegister(): void
    {
        $page = $this->session->open('/' . ProbeMall::SLUG . '/registers');
        if (str_contains($page, 'Close register')) {
            return;
        }
        $pattern = '#(/' . ProbeMall::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#';
        if (preg_match($pattern, $page, $match) !== 1) {
            self::fail('the open register action was not on the page');
        }
        $fields = [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
        ];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        $opened = $this->session->submit($match[1], $fields);
        self::assertSees('Close register', $opened);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $this->session->open('/' . ProbeMall::SLUG . '/vendors/new');
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/vendors', [
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
        $this->session->open('/' . ProbeMall::SLUG . '/booths/new');
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }
}
