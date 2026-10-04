<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class CoverageRemainderTest extends WebTestCase
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

    public function testTheWaitListVendorAndBoothPages(): void
    {
        $empty = $this->client->get('/' . self::SLUG . '/wait-list?status=declined&q=nobody-waiting');
        self::assertSees('Wait list', $empty);
        $form = $this->client->get('/' . self::SLUG . '/wait-list/new');
        self::assertSees('New person', $form);
        $tag = bin2hex(random_bytes(3));
        $refused = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => 'Lane',
            'email' => 'not-an-email',
            'phone' => '555-0100',
            'width_ft' => '10',
            'depth_ft' => '',
        ]);
        self::assertSees('preferred size is whole feet', $refused);
        $nameless = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => '',
            'last_name' => 'Lane',
            'email' => 'wait@example.test',
            'phone' => '555-0100',
        ]);
        self::assertSees('a first name is required', $nameless);
        $email = 'wait-' . $tag . '@example.test';
        $added = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => $email,
            'phone' => '555-0100',
            'width_ft' => '8',
            'depth_ft' => '10',
            'notes' => 'corner booth',
        ]);
        self::assertSees('Added to the wait list.', $added);
        self::assertSees($email, $added);
        $id = $this->match($added->body, '#/wait-list/([0-9a-fA-F-]{36})/edit#');
        $duplicate = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => $email,
            'phone' => '555-0100',
        ]);
        self::assertSees('That email is already on the wait list.', $duplicate);
        $filtered = $this->client->get('/' . self::SLUG . '/wait-list?status=waiting&q=' . $tag . '&confirm=' . $id);
        self::assertSees('Remove this person?', $filtered);
        $edited = $this->client->post('/' . self::SLUG . '/wait-list/' . $id, [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => $email,
            'phone' => '555-0101',
            'width_ft' => '8',
            'depth_ft' => '10',
            'status' => 'contacted',
            'notes' => 'called once',
        ]);
        self::assertSees('Wait list saved.', $edited);
        self::assertSees('Contacted', $edited);
        $removed = $this->client->post('/' . self::SLUG . '/wait-list/' . $id . '/delete', []);
        self::assertSees('Removed from the wait list.', $removed);

        $this->client->get('/' . self::SLUG . '/vendors/new');
        $badTake = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'v' . $tag,
            'first_name' => 'Ada',
            'last_name' => $tag,
            'store_take' => '150',
        ]);
        self::assertSees('store take is a percent from 0 to 100', $badTake);
        $code = 'v' . $tag;
        $created = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $tag,
            'phone' => '555-0199',
            'email' => 'ada-' . $tag . '@example.test',
            'store_take' => '0',
            'tax_exempt' => '1',
            'tax_exempt_number' => 'EX-' . $tag,
            'notes' => 'coverage vendor',
        ]);
        self::assertSees('Vendor created.', $created);
        $again = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $tag,
        ]);
        self::assertSees('That code is already used.', $again);
        $directory = $this->client->get('/' . self::SLUG . '/parties?status=inactive&q=' . $code);
        self::assertSees('aria-current="page">Inactive', $directory);
        $this->client->get('/' . self::SLUG . '/parties?status=ending&q=nomatch-vendor');
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', $code);
        $record = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSees('Assign a booth', $record);
        self::assertSees('stat-action', $record);
        self::assertSees('Pay this vendor', $record);
        $saved = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId, [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $tag . 'x',
            'store_take' => '12.5',
        ]);
        self::assertSees('Vendor saved.', $saved);

        $boothCode = 'b' . $tag;
        $this->client->get('/' . self::SLUG . '/booths/new');
        $booth = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $boothCode,
            'name' => 'Booth ' . $tag,
            'width_ft' => '12',
            'depth_ft' => '8',
            'month_start' => '2026-09-01',
            'amount' => '100.00',
        ]);
        self::assertSees('Booth created.', $booth);
        $boothId = HtmlPage::taggedId($booth->body, 'tr', 'booth', $boothCode);
        $this->client->get('/' . self::SLUG . '/booths?standing=available&q=' . $boothCode);
        $edit = $this->client->get('/' . self::SLUG . '/booths/' . $boothId . '/edit');
        self::assertSees('Edit booth', $edit);
        $renamed = $this->client->post('/' . self::SLUG . '/booths/' . $boothId, [
            'code' => $boothCode,
            'name' => 'Booth ' . $tag . ' renamed',
            'width_ft' => '12',
            'depth_ft' => '8',
        ]);
        self::assertSees('Booth saved.', $renamed);
        $missingBooth = $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => $vendorId,
            'booth_id' => '',
            'starts_on' => '2026-09-15',
        ]);
        self::assertLessThan(500, $missingBooth->status);
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => $vendorId,
            'booth_id' => $boothId,
            'starts_on' => '2026-09-15',
            'ends_on' => '',
            'rent' => '80.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);
        self::assertSees('Overridden', $assigned);
        $assignmentId = $this->match($assigned->body, '#/assignments/([0-9a-fA-F-]{36})/end#');
        $ended = $this->client->post('/' . self::SLUG . '/assignments/' . $assignmentId . '/end', [
            'ends_on' => '2026-09-20',
        ]);
        self::assertSees('Booth assignment ended.', $ended);

        $spare = 'c' . $tag;
        $spareBooth = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $spare,
            'name' => 'Spare ' . $tag,
        ]);
        self::assertSees('Booth created.', $spareBooth);
        $spareId = HtmlPage::taggedId($spareBooth->body, 'tr', 'booth', $spare);
        $confirm = $this->client->get('/' . self::SLUG . '/booths?confirm=' . $spareId);
        self::assertSees($spare, $confirm);
        $deletedBooth = $this->client->post('/' . self::SLUG . '/booths/' . $spareId . '/delete', []);
        self::assertSees('Booth deleted.', $deletedBooth);

        $floorName = 'Wing ' . $tag;
        self::makeRoomForAFloor($this->client, self::SLUG);
        $floor = $this->client->post('/' . self::SLUG . '/map/floors', ['name' => $floorName]);
        self::assertSees('Floor added.', $floor);
        $floorId = $this->match($floor->body, '/data-floor="([0-9a-fA-F-]{36})" data-floor-name="' . preg_quote($floorName, '/') . '"/');
        $map = $this->client->get('/' . self::SLUG . '/map?floor=' . $floorId);
        self::assertSees($floorName, $map);
        $removedFloor = $this->client->post('/' . self::SLUG . '/map/floors/' . $floorId . '/delete', []);
        self::assertSees('Floor removed.', $removedFloor);
    }

    public function testAMidMonthMoveInAndAnOverrideCharge(): void
    {
        $tag = bin2hex(random_bytes(3));
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'n' . $tag,
            'first_name' => 'Nia',
            'last_name' => $tag,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', 'n' . $tag);

        $moveIn = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'm' . $tag,
            'name' => 'Move ' . $tag,
            'month_start' => '2026-06-01',
            'amount' => '90.00',
        ]);
        self::assertSees('Booth created.', $moveIn);
        $moveInId = HtmlPage::taggedId($moveIn->body, 'tr', 'booth', 'm' . $tag);
        $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => $vendorId,
            'booth_id' => $moveInId,
            'starts_on' => '2026-06-15',
        ]);
        $june = $this->client->get('/' . self::SLUG . '/rent?month=2026-06');
        self::assertSees('Charge $48.00', $june);
        $charged = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $moveInId,
            'posting_date' => '2026-06-15',
            'month' => '2026-06',
        ]);
        self::assertSees('Rent charged.', $charged);

        $special = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'o' . $tag,
            'name' => 'Override ' . $tag,
            'month_start' => '2026-03-01',
            'amount' => '31.00',
        ]);
        self::assertSees('Booth created.', $special);
        $specialId = HtmlPage::taggedId($special->body, 'tr', 'booth', 'o' . $tag);
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => $vendorId,
            'booth_id' => $specialId,
            'starts_on' => '2026-03-15',
            'rent' => '62.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);
        $march = $this->client->get('/' . self::SLUG . '/rent?month=2026-03');
        self::assertSees('Charge $34.00', $march);
        $overridden = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $specialId,
            'posting_date' => '2026-03-15',
            'month' => '2026-03',
        ]);
        self::assertSees('Rent charged.', $overridden);

        $vacant = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'v' . $tag,
            'name' => 'Vacant ' . $tag,
            'month_start' => '2026-06-01',
            'amount' => '77.00',
        ]);
        self::assertSees('Booth created.', $vacant);
        $empty = $this->client->get('/' . self::SLUG . '/rent?month=2026-06');
        self::assertSees('v' . $tag, $empty);
        self::assertStringNotContainsString('Charge $77.00', $empty->body);
    }

    public function testRegistersRentPayoutAndTheOpenCart(): void
    {
        $this->activateRegister();
        $tag = bin2hex(random_bytes(3));
        $blank = $this->client->get('/' . self::SLUG . '/registers/new');
        self::assertSees('New register', $blank);
        $unnamed = $this->client->post('/' . self::SLUG . '/registers', ['name' => '']);
        self::assertSees('a register name is required', $unnamed);
        $name = 'Cov ' . $tag;
        $added = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name]);
        self::assertSees('Register added.', $added);
        $duplicate = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name]);
        self::assertLessThan(500, $duplicate->status);
        self::assertNotSame('', HtmlPage::error($duplicate->body));
        $registerId = $this->match($added->body, '/id="register-([0-9a-fA-F-]{36})">\s*<div class="card-head">\s*<div class="h3">' . preg_quote($name, '/') . '/');
        $edit = $this->client->get('/' . self::SLUG . '/registers/' . $registerId . '/edit');
        self::assertSees($name, $edit);
        $renamed = $this->client->post('/' . self::SLUG . '/registers/' . $registerId, ['name' => $name . ' x']);
        self::assertSees('Register renamed.', $renamed);
        $idle = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/use', []);
        self::assertSees('That register is not open.', $idle);
        $closeHint = $this->client->get('/' . self::SLUG . '/registers?close=' . $registerId);
        self::assertSees($name . ' x', $closeHint);
        $deleted = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/delete', []);
        self::assertSees('Register deleted.', $deleted);

        $code = 'p' . $tag;
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Pat',
            'last_name' => $tag,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', $code);
        $rent = $this->client->get('/' . self::SLUG . '/rent');
        self::assertSees('<h1 class="h1">Rent</h1>', $rent);
        // today( is the mall date shown on the book.
        $today = $this->match($rent->body, '/<time datetime="(\d{4}-\d{2}-\d{2})">/');
        $month = substr($today, 0, 8) . '01';
        $boothCode = 'r' . $tag;
        $booth = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $boothCode,
            'name' => 'Rent ' . $tag,
            'month_start' => $month,
            'amount' => '40.00',
        ]);
        $boothId = HtmlPage::taggedId($booth->body, 'tr', 'booth', $boothCode);
        $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => $vendorId,
            'booth_id' => $boothId,
            'starts_on' => $month,
        ]);
        $book = $this->client->get('/' . self::SLUG . '/rent?month=' . substr($today, 0, 7));
        self::assertSees($boothCode, $book);
        self::assertSees('Charge $40.00', $book);
        // chosen( keeps the month from the query.
        self::assertSees('Still to charge', $book);
        $noRate = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => '2020-01-15',
        ]);
        self::assertSees('a rent charge requires the rate stored for that month', $noRate);
        $charged = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => $today,
        ]);
        self::assertSees('Rent charged.', $charged);
        $noReference = $this->client->post('/' . self::SLUG . '/rent/payment', [
            'source_reference' => '',
            'booth_id' => $boothId,
            'amount' => '1.00',
            'posting_date' => $today,
        ]);
        self::assertSees('a source reference is required', $noReference);
        $badAmount = $this->client->post('/' . self::SLUG . '/rent/payment', [
            'source_reference' => 'rent' . $tag,
            'booth_id' => $boothId,
            'amount' => '0',
            'posting_date' => $today,
        ]);
        self::assertLessThan(500, $badAmount->status);
        $paidRent = $this->client->post('/' . self::SLUG . '/rent/payment', [
            'source_reference' => 'rent' . $tag . 'b',
            'booth_id' => $boothId,
            'amount' => '10.00',
            'posting_date' => $today,
        ]);
        self::assertSees('Rent payment posted.', $paidRent);

        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $piece = $this->piece($vendorId, '10.00', '6');
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $piece['code']]);
        self::assertSees($piece['description'], $cart);
        $lineNo = $this->match($cart->body, '/name="line_no" value="(\d+)"/');
        $quantity = $this->client->post('/' . self::SLUG . '/pos/quantity', [
            'line_no' => $lineNo,
            'quantity' => '3',
        ]);
        self::assertSees('Quantity saved.', $quantity);
        $tooMany = $this->client->post('/' . self::SLUG . '/pos/quantity', [
            'line_no' => $lineNo,
            'quantity' => '99',
        ]);
        self::assertSees('there are not enough on hand', $tooMany);
        $single = $this->client->post('/' . self::SLUG . '/pos/quantity', [
            'line_no' => $lineNo,
            'quantity' => '1',
        ]);
        self::assertSees('Quantity saved.', $single);
        $cleared = $this->client->post('/' . self::SLUG . '/pos/line-discount', [
            'line_no' => $lineNo,
            'discount_action' => 'remove',
            'discount_kind' => 'amount',
            'discount_value' => '',
            'discount_reason' => '',
        ]);
        self::assertSees('Line discount saved.', $cleared);
        $due = $this->match($cleared->body, '/register-due">\$([0-9]+\.[0-9]{2})/');
        $missingCheck = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_checks' => $due,
        ]);
        self::assertSees('a check sale needs a check number', $missingCheck);
        $sold = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_checks' => $due,
            'check_number' => 'CHK' . $tag,
        ]);
        self::assertSees('Sale posted.', $sold);
        $itemId = $this->match($sold->body, '/name="item_id" value="([0-9a-fA-F-]{36})"/');
        $returnPath = '/' . self::SLUG . '/pos/receipt/' . $this->match($sold->body, '#/pos/receipt/([0-9a-fA-F-]{36})/return#') . '/return';
        $bareReturn = $this->client->post($returnPath, [
            'item_id' => $itemId,
            'return_tender' => 'checks',
        ]);
        self::assertSees('a check sale needs a check number', $bareReturn);
        $returned = $this->client->post($returnPath, [
            'item_id' => $itemId,
            'return_tender' => 'checks',
            'return_check_number' => 'RET' . $tag,
        ]);
        self::assertSees('Piece returned.', $returned);

        $held = $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'card',
            'tender_amount' => '1.00',
        ]);
        if (str_contains($held->body, 'Tender saved.')) {
            $removed = $this->client->post('/' . self::SLUG . '/pos/tender/remove', ['tender_role' => 'card']);
            self::assertLessThan(500, $removed->status);
        }
        $this->client->post('/' . self::SLUG . '/pos/discard', []);

        $payout = $this->client->get('/' . self::SLUG . '/payout?vendor=' . $vendorId);
        self::assertSees('Their share', $payout);
        self::assertDoesNotMatchRegularExpression('/name="month"|name="posting_date"|Statement/', $payout->body);
        $posted = $this->client->post('/' . self::SLUG . '/payout', [
            'vendor_id' => $vendorId,
        ]);
        self::assertTrue(
            str_contains($posted->body, 'Payout posted.') || str_contains($posted->body, 'a payout clears'),
            HtmlPage::error($posted->body)
        );
        $inactive = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/standing', [
            'standing' => 'inactive',
        ]);
        self::assertLessThan(500, $inactive->status);
    }

    public function testTheBookExportAndCatalogEdges(): void
    {
        $journals = $this->client->get('/' . self::SLUG . '/journals');
        self::assertSees('Journals', $journals);
        $journalId = $this->match($journals->body, '#/journals/([0-9a-fA-F-]{36})#');
        $journal = $this->client->get('/' . self::SLUG . '/journals/' . $journalId);
        self::assertSees('Journal', $journal);
        $adjustment = $this->client->get('/' . self::SLUG . '/adjustment');
        $cash = $this->account($adjustment->body, '1000');
        $bank = $this->account($adjustment->body, '1010');
        $empty = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => '',
            'posting_date' => '2026-06-15',
            'description' => '',
        ]);
        self::assertSees('a source reference is required', $empty);
        $oneLine = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => 'one' . bin2hex(random_bytes(2)),
            'posting_date' => '2026-06-15',
            'description' => 'one line',
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
        ]);
        self::assertSees('a journal needs at least two lines', $oneLine);
        $reference = 'yr' . bin2hex(random_bytes(3));
        $opened = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => $reference,
            'posting_date' => '2018-03-15',
            'description' => 'Open 2018',
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
            'line_account_2' => $bank,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ]);
        $this->assertOpenedDay($opened);
        $later = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => $reference . 'b',
            'posting_date' => '2036-03-15',
            'description' => 'Open 2036',
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
            'line_account_2' => $bank,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ]);
        $this->assertOpenedDay($later);
        $overlap = $this->client->post('/' . self::SLUG . '/periods', [
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => ProbeMall::PERIOD_END,
        ]);
        self::assertLessThan(500, $overlap->status);
        $close = $this->client->post('/' . self::SLUG . '/close', [
            'period_id' => '01900000-0000-7000-8000-0000000000ff',
            'ends_on' => '2018-12-31',
        ]);
        self::assertLessThan(500, $close->status);

        $export = $this->client->get('/' . self::SLUG . '/export');
        self::assertSees('QuickBooks Online', $export);
        $badExport = $this->client->post('/' . self::SLUG . '/export', [
            'from' => '2030-01-01',
            'to' => '2020-01-01',
            'system' => 'nope',
            'tax_rate' => '',
        ]);
        self::assertLessThan(500, $badExport->status);
        $xero = $this->client->post('/' . self::SLUG . '/export', [
            'from' => '2020-01-01',
            'to' => '2020-01-31',
            'system' => 'xero',
            'tax_rate' => 'Tax Exempt',
        ]);
        self::assertSame(200, $xero->status);

        $tax = $this->client->post('/' . self::SLUG . '/tax', [
            'source_reference' => '',
            'amount' => '1.00',
            'credit' => 'bank',
            'posting_date' => '2026-06-15',
        ]);
        self::assertSees('a source reference is required', $tax);
        $cashCredit = $this->client->post('/' . self::SLUG . '/tax', [
            'source_reference' => 'tax' . bin2hex(random_bytes(2)),
            'amount' => '0.01',
            'credit' => 'cash',
            'posting_date' => '2026-06-15',
        ]);
        self::assertSees('a tax payment credits the bank', $cashCredit);

        $settings = [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => 'nope',
            'tax_rate' => '0',
            'floor_days' => '',
        ];
        self::assertSees('store take is a percent from 0 to 100', $this->client->post('/' . self::SLUG . '/settings', $settings));
        $settings['default_store_take'] = '0';
        $settings['tax_rate'] = 'nope';
        self::assertSees('tax rate is a percent from 0 to 100', $this->client->post('/' . self::SLUG . '/settings', $settings));
        $settings['tax_rate'] = '0';
        $settings['floor_days'] = '999';
        self::assertSees('days on the floor is a whole number', $this->client->post('/' . self::SLUG . '/settings', $settings));
        $settings['floor_days'] = '';
        $settings['booth_width_ft'] = '0';
        self::assertSees('booth size is whole feet', $this->client->post('/' . self::SLUG . '/settings', $settings));
        $settings['booth_width_ft'] = '10';
        $settings['floor_width_ft'] = 'wide';
        self::assertSees('floor size is whole feet', $this->client->post('/' . self::SLUG . '/settings', $settings));
        $settings['floor_width_ft'] = '120';
        $settings['time_zone'] = 'Not/AZone';
        self::assertLessThan(500, $this->client->post('/' . self::SLUG . '/settings', $settings)->status);

        $brand = 'Brand ' . bin2hex(random_bytes(3));
        $this->client->get('/' . self::SLUG . '/brands/new');
        $listed = $this->client->get('/' . self::SLUG . '/brands?new=1&q=');
        self::assertSees('Brands', $listed);
        $blankName = $this->client->post('/' . self::SLUG . '/brands', ['name' => '']);
        self::assertSees('Enter a brand name.', $blankName);
        $created = $this->client->post('/' . self::SLUG . '/brands', ['name' => $brand]);
        self::assertSees($brand, $created);
        $brandId = HtmlPage::taggedId($created->body, 'tr', 'brand', $brand);
        $rename = $this->client->get('/' . self::SLUG . '/brands/' . $brandId . '/edit');
        self::assertSees($brand, $rename);
        $search = $this->client->get('/' . self::SLUG . '/brands?q=' . rawurlencode($brand) . '&confirm=' . $brandId);
        self::assertSees($brand, $search);

        $vendorCode = 'i' . bin2hex(random_bytes(3));
        $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $vendorCode,
            'first_name' => 'Ivy',
            'last_name' => $vendorCode,
            'store_take' => '0',
        ]);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', $vendorCode);
        $vendors = $this->client->get('/' . self::SLUG . '/inventory/vendors.json?q=' . $vendorCode);
        self::assertStringContainsString($vendorCode, $vendors->body);
        $byCode = $this->client->get('/' . self::SLUG . '/inventory/vendors.json?code=' . $vendorCode);
        self::assertStringContainsString($vendorCode, $byCode->body);
        $brands = $this->client->get('/' . self::SLUG . '/inventory/brands.json?q=' . rawurlencode($brand));
        self::assertStringContainsString($brand, $brands->body);
        $intakeBrand = $this->client->post('/' . self::SLUG . '/inventory/brands', ['name' => 'Intake ' . $brand]);
        self::assertSame(200, $intakeBrand->status);
        $fresh = $this->client->get('/' . self::SLUG . '/inventory/new?vendor=' . $vendorId);
        self::assertSees('New piece', $fresh);
        $badPiece = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => 'bad' . $vendorCode,
            'description' => 'Bad',
            'vendor_id' => $vendorId,
            'price' => '1.00',
            'quantity' => '0',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Enter a quantity of at least 1.', $badPiece);
        $piece = $this->piece($vendorId, '3.00', '2');
        $inventory = $this->client->get('/' . self::SLUG . '/inventory?q=' . $piece['code']);
        $itemId = HtmlPage::taggedId($inventory->body, 'tr', 'item', $piece['code']);
        $editor = $this->client->get('/' . self::SLUG . '/inventory/' . $itemId . '/edit');
        self::assertSees($piece['description'], $editor);
        $asked = $this->client->get('/' . self::SLUG . '/inventory?confirm=' . $itemId);
        self::assertSees($piece['description'], $asked);
        $gone = $this->client->post('/' . self::SLUG . '/inventory/' . $itemId . '/delete', []);
        self::assertSees('Piece deleted.', $gone);

        $signOut = $this->client->post('/' . self::SLUG . '/logout', []);
        self::assertSees('Sign out', $signOut);
        self::assertSees('Last backup you downloaded', $signOut);
    }

    private function activateRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $active = $this->client->post($use[1], []);
            self::assertSees('is the active register.', $active);

            return;
        }
        if (!str_contains($page->body, '/open')) {
            $created = $this->client->post('/' . self::SLUG . '/registers', [
                'name' => 'Open ' . bin2hex(random_bytes(2)),
            ]);
            self::assertSees('Register added.', $created);
            $page = $created;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $opened = $this->client->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ]);
        self::assertSees('Register opened.', $opened);
    }

    /**
     * @return array{code: string, description: string}
     */
    private function piece(string $vendorId, string $price, string $quantity): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $description = 'Piece ' . $code;
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'quantity' => $quantity,
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Piece created.', $created);

        return ['code' => $code, 'description' => $description];
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

    private function assertOpenedDay(\PerfectConsign\Tests\Support\PageResponse $page): void
    {
        self::assertTrue(
            str_contains($page->body, 'Adjustment posted.')
            || str_contains($page->body, 'That day could not be opened.')
            || str_contains($page->body, 'That day is already closed.'),
            HtmlPage::error($page->body)
        );
    }

    private function account(string $html, string $code): string
    {
        return $this->match($html, '/<option value="([0-9a-fA-F-]{36})">' . $code . ' /');
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
