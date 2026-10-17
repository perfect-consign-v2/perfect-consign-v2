<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\PageResponse;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class FloorCoverageTest extends WebTestCase
{
    private FormClient $client;

    private const SLUG = ProbeMall::SLUG;

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

    public function testAnEmptyCartDoesNotBlockTheRegister(): void
    {
        $this->settings(false);
        $this->openRegister();
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $vendor = $this->vendor();
        $item = $this->item($vendor, '8.00');
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        $lineNo = $this->match($cart->body, '/name="line_no" value="(\d+)"/');
        $cleared = $this->client->post('/' . self::SLUG . '/pos/remove', ['line_no' => $lineNo]);
        self::assertSees('Item removed.', $cleared);
        self::assertSees('Cart is empty.', $cleared);
        self::assertStringNotContainsString('Suspend Sale', $cleared->body);
        $registers = $this->client->get('/' . self::SLUG . '/registers');
        self::assertStringNotContainsString('A sale is still open on this register.', $registers->body);
    }

    public function testTheFloorVoidsReturnsAndParks(): void
    {
        $this->settings(false);
        $this->openRegister();
        $vendor = $this->vendor();
        $first = $this->item($vendor, '10.00');
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $first['code']]);
        self::assertSees($first['description'], $cart);
        $registers = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('/id="register-([0-9a-fA-F-]{36})">.*?Active/s', $registers->body, $active) === 1) {
            $registerId = $active[1];
        } else {
            $registerId = $this->match($registers->body, '#/registers/([0-9a-fA-F-]{36})/close#');
        }
        $expected = $this->match(
            $registers->body,
            '/id="register-' . $registerId . '".*?Drawer expected \$?([0-9]+\.[0-9]{2})/s'
        );
        $blocked = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/close', $this->closeFields($expected));
        self::assertSees('A sale is still open on this register. Finish it or cancel it, then close.', $blocked);
        $this->client->post('/' . self::SLUG . '/registers/close-cancel', []);
        $parked = $this->client->post('/' . self::SLUG . '/pos/park', []);
        self::assertSees('Ticket parked.', $parked);
        $suspended = $this->client->get('/' . self::SLUG . '/pos/suspended');
        self::assertSees('name="ticket_id"', $suspended);
        $ticketId = $this->lastMatch($suspended->body, '/name="ticket_id" value="([0-9a-fA-F-]{36})"/');
        $recalled = $this->client->post('/' . self::SLUG . '/pos/recall', ['ticket_id' => $ticketId]);
        self::assertSees($first['description'], $recalled);
        $second = $this->item($vendor, '4.00');
        $withTwo = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $second['code']]);
        $lineNo = $this->match(
            $withTwo->body,
            '/' . preg_quote($second['description'], '/') . '.*?name="line_no" value="(\d+)"/s'
        );
        $removed = $this->client->post('/' . self::SLUG . '/pos/remove', ['line_no' => $lineNo]);
        self::assertStringNotContainsString($second['description'], $removed->body);
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_cash' => '4.00',
            'tender_card' => '6.00',
            'cash_received' => '4.00',
        ]);
        self::assertSees('Sale posted.', $paid);
        $itemId = $this->match($paid->body, '/name="item_id" value="([0-9a-fA-F-]{36})"/');
        $returned = $this->client->post($this->receiptAction($paid->body, 'return'), [
            'item_id' => $itemId,
            'return_tender' => 'cash',
        ]);
        self::assertSees('Item returned.', $returned);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $again = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $first['code']]);
        self::assertSees($first['description'], $again);
        $resold = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_cash' => '10.00',
            'cash_received' => '10.00',
        ]);
        self::assertSees('Sale posted.', $resold);
        $journalId = $this->match($resold->body, '#/pos/receipt/([0-9a-fA-F-]{36})/void#');
        $voided = $this->client->post('/' . self::SLUG . '/pos/receipt/' . $journalId . '/void', []);
        self::assertSees('Ticket voided.', $voided);
        self::assertSees('slip-void">Voided', $voided);
        $secondVoid = $this->client->post('/' . self::SLUG . '/pos/receipt/' . $journalId . '/void', []);
        self::assertSees('a second void is refused', $secondVoid);
    }

    public function testADiscountAndATaxWaiverStayOnTheReceipt(): void
    {
        $this->settings(false);
        $this->openRegister();
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => '50',
        ]);
        self::assertSees('Vendor created.', $created);
        $directory = $this->client->get('/' . self::SLUG . '/parties');
        $vendor = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $code);
        $first = $this->item($vendor, '10.00');
        $second = $this->item($vendor, '30.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $first['code']]);
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $second['code']]);
        self::assertSees('10.00', $cart);
        self::assertSees('30.00', $cart);
        $discounted = $this->client->post('/' . self::SLUG . '/pos/discount', [
            'discount_action' => 'apply',
            'discount_kind' => 'percent',
            'discount_value' => '10',
            'discount_reason' => 'promotion',
        ]);
        self::assertSees('Discount saved.', $discounted);
        self::assertSees('id="discount"', $discounted);
        self::assertSees('promotion', $discounted);
        self::assertSees('36.00', $discounted);
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_cash' => '36.00',
            'cash_received' => '36.00',
        ]);
        self::assertSees('Sale posted.', $paid);
        self::assertSees('id="discount"', $paid);
        self::assertSees('promotion', $paid);
        $itemId = $this->match(
            $paid->body,
            '/' . preg_quote($first['description'], '/') . '.*?name="item_id" value="([0-9a-fA-F-]{36})"/s'
        );
        $returned = $this->client->post($this->receiptAction($paid->body, 'return'), [
            'item_id' => $itemId,
            'return_tender' => 'cash',
        ]);
        self::assertSees('Item returned.', $returned);
        self::assertSees($second['description'], $returned);
        $third = $this->item($vendor, '8.00');
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $third['code']]);
        $waived = $this->client->post('/' . self::SLUG . '/pos/tax-free', [
            'waiver_action' => 'waive',
            'waiver_reason' => 'charity',
        ]);
        self::assertSees('Tax updated.', $waived);
        self::assertSees('Tax waived', $waived);
        self::assertSees('charity', $waived);
        $free = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_card' => '8.00',
        ]);
        self::assertSees('Sale posted.', $free);
        self::assertSees('Tax waived', $free);
        self::assertSees('charity', $free);
    }

    public function testARegisterDropsAndPrintsTheTape(): void
    {
        $name = 'Tape ' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name]);
        self::assertSees($name, $created);
        $registerId = $this->match(
            $created->body,
            '/id="register-([0-9a-fA-F-]{36})">\s*<div class="card-head">\s*<div class="h3">' . preg_quote($name, '/') . '/'
        );
        $opened = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/open', [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '20.00',
            ...$this->zeroCounts(),
        ]);
        self::assertSees('Register opened.', $opened);
        $today = $this->match($opened->body, '/name="posting_date"[^>]*max="(\d{4}-\d{2}-\d{2})"/');
        $dropped = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/drop', [
            'amount' => '5.00',
            'posting_date' => $today,
        ]);
        self::assertSees('Cash dropped.', $dropped);
        $shifts = $this->client->get('/' . self::SLUG . '/registers/' . $registerId . '/shifts');
        self::assertSees($name . ' shifts', $shifts);
        self::assertSees('Open', $shifts);
        $closed = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/close', $this->closeFields('15.00'));
        self::assertSees('Register closed.', $closed);
        $listed = $this->client->get('/' . self::SLUG . '/registers/' . $registerId . '/shifts');
        $sessionId = $this->match($listed->body, '#/registers/sessions/([0-9a-fA-F-]{36})#');
        $tape = $this->client->get('/' . self::SLUG . '/registers/sessions/' . $sessionId);
        self::assertSees('Opening count', $tape);
        self::assertSees('Cash drops', $tape);
    }

    public function testACardAndCheckCountStaysOffTheLedger(): void
    {
        $even = $this->openNamedRegister('Card');
        $closed = $this->client->post('/' . self::SLUG . '/registers/' . $even . '/close', [
            'counted_cash' => '20.00',
            'counted_card' => '1.00',
            'counted_checks' => '2.00',
            ...$this->zeroCounts(),
        ], 60);
        $this->requireText('Register closed.', $closed, 'even close');
        $shifts = $this->client->get('/' . self::SLUG . '/registers/' . $even . '/shifts');
        $this->requireText('Counted $1.00, posted $0.00', $shifts, 'card count');
        $this->requireText('Counted $2.00, posted $0.00', $shifts, 'checks count');
        self::assertStringNotContainsString(' over', $shifts->body);
        self::assertStringNotContainsString(' short', $shifts->body);
        self::assertStringNotContainsString('diff-over', $shifts->body);
        self::assertStringNotContainsString('diff-short', $shifts->body);
        self::assertMatchesRegularExpression(
            '/Counted \$1\.00, posted \$0\.00<\/td>\s*<td>Counted \$2\.00, posted \$0\.00<\/td>/',
            $shifts->body
        );
        $sessionId = $this->match($shifts->body, '#/registers/sessions/([0-9a-fA-F-]{36})#');
        $tape = $this->client->get('/' . self::SLUG . '/registers/sessions/' . $sessionId);
        $this->requireText('Card posted $0.00, counted $1.00', $tape, 'card tape');
        $this->requireText('Checks posted $0.00, counted $2.00', $tape, 'checks tape');

        $short = $this->openNamedRegister('Short');
        $ask = $this->client->post('/' . self::SLUG . '/registers/' . $short . '/close', $this->closeFields('19.00'), 60);
        $this->requireText('Counted cash does not match the drawer.', $ask, 'cash short');
        $confirmed = $this->client->post('/' . self::SLUG . '/registers/' . $short . '/close', [
            ...$this->closeFields('19.00'),
            'confirm_variance' => 'yes',
        ], 60);
        $this->requireText('Register closed.', $confirmed, 'cash close');
        $this->client->post('/' . self::SLUG . '/registers/close-cancel', []);
        $again = $this->client->get('/' . self::SLUG . '/registers/' . $short . '/shifts');
        $this->requireText('$1.00 short', $again, 'cash cell');
        $this->requireText('Counted $0.00, posted $0.00', $again, 'zero card');
        self::assertMatchesRegularExpression(
            '/\$1\.00 short<\/td>\s*<td>Counted \$0\.00, posted \$0\.00<\/td>\s*<td>Counted \$0\.00, posted \$0\.00<\/td>/',
            $again->body
        );
    }

    private function requireText(string $text, PageResponse $page, string $step): void
    {
        self::assertStringContainsString($text, $page->body, $step . ' ' . $page->status . ' ' . HtmlPage::error($page->body));
    }

    private function openNamedRegister(string $label): string
    {
        $name = $label . ' ' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name], 60);
        $this->requireText($name, $created, 'create ' . $label);
        $registerId = $this->match(
            $created->body,
            '/id="register-([0-9a-fA-F-]{36})">\s*<div class="card-head">\s*<div class="h3">' . preg_quote($name, '/') . '/'
        );
        $opened = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/open', [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '20.00',
            ...$this->zeroCounts(),
        ], 60);
        $this->requireText('Register opened.', $opened, 'open ' . $label);

        return $registerId;
    }

    public function testTheBooksPagesPost(): void
    {
        $map = $this->client->get('/' . self::SLUG . '/map');
        self::assertSees('Booth map', $map);
        $editor = $this->client->get('/' . self::SLUG . '/map/edit');
        self::assertSees('Edit floor map', $editor);
        $floorName = 'Loft ' . bin2hex(random_bytes(2));
        self::makeRoomForAFloor($this->client, self::SLUG);
        $added = $this->client->post('/' . self::SLUG . '/map/floors', ['name' => $floorName]);
        self::assertSees('Floor added.', $added);
        $floorId = $this->match(
            $added->body,
            '/data-floor="([0-9a-fA-F-]{36})" data-floor-name="' . preg_quote($floorName, '/') . '"/'
        );
        $renamed = $this->client->post('/' . self::SLUG . '/map/floors/' . $floorId, ['name' => $floorName . 'x']);
        self::assertSees('Floor renamed.', $renamed);
        $code = 'b' . bin2hex(random_bytes(3));
        $booth = $this->client->post('/' . self::SLUG . '/booths', ['code' => $code, 'name' => $code]);
        $boothId = HtmlPage::taggedId($booth->body, 'tr', 'booth', $code);
        $layout = json_encode([[
            'id' => $boothId,
            'floorId' => $floorId,
            'x' => 1,
            'z' => 1,
            'width' => null,
            'depth' => null,
        ]], JSON_THROW_ON_ERROR);
        $saved = $this->client->post('/' . self::SLUG . '/map/edit', [
            'floor' => $floorId,
            'layout' => $layout,
        ]);
        self::assertSees('Map saved.', $saved);
        $cleared = $this->client->post('/' . self::SLUG . '/map/edit', [
            'floor' => $floorId,
            'layout' => '[]',
        ]);
        self::assertSees('Map saved.', $cleared);
        $removed = $this->client->post('/' . self::SLUG . '/map/floors/' . $floorId . '/delete', []);
        self::assertSees('Floor removed.', $removed);

        $export = $this->client->post('/' . self::SLUG . '/export', [
            'from' => '2020-01-01',
            'to' => '2035-12-31',
            'system' => 'quickbooks',
            'tax_rate' => 'Tax Exempt',
        ]);
        self::assertSame(200, $export->status);
        self::assertNotSame('', $export->body);

        $adjustmentForm = $this->client->get('/' . self::SLUG . '/adjustment');
        self::assertSees('Fix a mistake', $adjustmentForm);
        $cash = $this->account($adjustmentForm->body, '1000');
        $bank = $this->account($adjustmentForm->body, '1010');
        $reference = 'cov' . bin2hex(random_bytes(3));
        $today = $this->match($adjustmentForm->body, '/id="posting_date"[^>]*value="(\d{4}-\d{2}-\d{2})"/');
        $posted = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => $reference,
            'posting_date' => $today,
            'description' => 'Coverage ' . $reference,
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
            'line_account_2' => $bank,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ]);
        self::assertSees('Adjustment posted.', $posted);
        self::assertSame(1, preg_match('#/journals/([0-9a-fA-F-]{36})/void#', $posted->body, $void));
        $voided = $this->client->post('/' . self::SLUG . '/journals/' . $void[1] . '/void', [
            'posting_date' => $today,
        ]);
        self::assertSees('Journal voided.', $voided);

        $taxForm = $this->client->get('/' . self::SLUG . '/tax');
        self::assertSees('Sales tax', $taxForm);
        $tax = $this->client->post('/' . self::SLUG . '/tax', [
            'source_reference' => 'tax' . bin2hex(random_bytes(3)),
            'amount' => '0.01',
            'credit' => 'bank',
            'posting_date' => $today,
        ]);
        self::assertLessThan(500, $tax->status);
        self::assertTrue(
            str_contains($tax->body, 'Tax payment posted.') || str_contains($tax->body, 'Sales tax'),
            HtmlPage::error($tax->body)
        );

        $opening = $this->client->get('/' . self::SLUG . '/opening');
        self::assertSees('Opening amounts', $opening);
        $refused = $this->client->post('/' . self::SLUG . '/opening', ['posting_date' => $today]);
        self::assertSees('an opening is available only while the book has no other journal', $refused);

        $this->client->post('/' . self::SLUG . '/periods', [
            'starts_on' => '2019-01-01',
            'ends_on' => '2019-12-31',
        ]);
        $close = $this->client->get('/' . self::SLUG . '/close');
        self::assertSees('Close the year', $close);
        $openPeriod = '/<tr>\s*<td>(\d{4}-\d{2}-\d{2})<\/td>\s*<td>(\d{4}-\d{2}-\d{2})<\/td>\s*<td>open<\/td>.*?name="period_id" value="([0-9a-fA-F-]{36})"/s';
        $open = [];
        if (preg_match_all($openPeriod, $close->body, $found, PREG_SET_ORDER) > 0) {
            $open = $found;
        }
        usort($open, static fn (array $left, array $right): int => $left[1] <=> $right[1]);
        foreach ($open as $period) {
            if ($period[1] > '2019-01-01') {
                continue;
            }
            $closed = $this->client->post('/' . self::SLUG . '/close', [
                'period_id' => $period[3],
                'ends_on' => $period[2],
            ]);
            self::assertSees('Period closed.', $closed);
        }

        $backup = $this->client->post('/' . self::SLUG . '/backup', ['return' => 'settings'], 90);
        self::assertSame(200, $backup->status);
        self::assertNotSame('', $backup->body);
    }

    public function testTheCartSplitsACertificateAndAVendorDraw(): void
    {
        $this->settings(false);
        $this->openRegister();
        $guard = 0;
        do {
            $pos = $this->client->get('/' . self::SLUG . '/pos');
            if (!str_contains($pos->body, 'data-open-modal="clear-cart"')) {
                break;
            }
            $discarded = $this->client->post('/' . self::SLUG . '/pos/discard', []);
            self::assertSees('Ticket discarded.', $discarded);
            $guard++;
        } while ($guard < 20);
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $created);
        $directory = $this->client->get('/' . self::SLUG . '/parties');
        $vendor = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $code);
        $booth = 'b' . bin2hex(random_bytes(3));
        $made = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $booth,
            'name' => $booth,
        ]);
        self::assertSees($booth, $made);
        $booths = $this->client->get('/' . self::SLUG . '/booths');
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $booth);
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendor,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
            'rent' => '150.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);
        $missing = $this->client->post('/' . self::SLUG . '/pos/instant', [
            'vendor_id' => '',
            'description' => 'Missing',
            'price' => '4.00',
        ]);
        self::assertSees('Choose a vendor.', $missing);
        $added = $this->client->post('/' . self::SLUG . '/pos/instant', [
            'vendor_id' => $vendor,
            'description' => 'Instant ' . $code,
            'price' => '12.00',
        ]);
        self::assertSees('Item added to cart.', $added);
        self::assertSees('Instant ' . $code, $added);
        self::assertSees('Clear cart', $added);
        // act(
        $cleared = $this->client->post('/' . self::SLUG . '/pos/discard', []);
        self::assertSees('Ticket discarded.', $cleared);
        self::assertStringNotContainsString('Clear cart', $cleared->body);
        $item = $this->item($vendor, '10.00');
        $suggest = $this->client->get('/' . self::SLUG . '/pos/suggest.json?q=' . $item['code']);
        self::assertStringContainsString($item['code'], $suggest->body);
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'cash',
            'tender_amount' => '4.00',
        ]);
        $split = $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'card',
            'tender_amount' => '6.00',
        ]);
        self::assertSees('Tender saved.', $split);
        self::assertSees('Still due', $split);
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $paid);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $overItem = $this->item($vendor, '15.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $overItem['code']]);
        $over = $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'vendor_draw',
            'tender_amount' => '12.00',
            'vendor_id' => $vendor,
        ]);
        self::assertSees('a vendor draw is limited to the open payable', $over);
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $number = 'gc' . $code;
        $sold = $this->gift('20.00', 'cash', $number);
        $this->certificate($sold, $number, '20.00');
        $generated = $this->gift('10.00', 'cash');
        $this->slip($generated);
        $blankNumber = $this->client->post('/' . self::SLUG . '/pos/gift', [
            'gift_custom' => '1',
            'gift_number' => ' ',
            'gift_amount' => '3.00',
            'gift_tender' => 'cash',
        ]);
        self::assertStringContainsString('Enter the certificate number.', $blankNumber->body);
        $missingCheck = $this->gift('12.00', 'checks');
        self::assertStringContainsString('id="gift-sell" class="modal" role="dialog" aria-modal="true" aria-labelledby="gift-sell-title">', $missingCheck->body);
        self::assertStringContainsString('id="gift-check-error">a check sale needs a check number', $missingCheck->body);
        self::assertStringNotContainsString('alert alert-danger', $missingCheck->body);
        $again = $this->gift('8.00', 'cash', $number);
        self::assertStringContainsString('id="gift-sell" class="modal" role="dialog" aria-modal="true" aria-labelledby="gift-sell-title">', $again->body);
        self::assertStringContainsString('id="gift-sell-error">That certificate number is already used.', $again->body);
        self::assertStringNotContainsString('alert alert-danger', $again->body);
        $balance = $this->client->get('/' . self::SLUG . '/pos?gift=' . $number);
        self::assertSees('20.00', $balance);
        $found = $this->giftCertificate($number);
        self::assertStringContainsString('"ok":true', $found->body);
        self::assertStringContainsString('$20.00', $found->body);
        $unknown = $this->giftCertificate('missing-' . $number);
        self::assertStringContainsString('that gift certificate number is not on file', $unknown->body);
        $redeemedItem = $this->item($vendor, '5.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $redeemedItem['code']]);
        $missing = $this->payment([
            'tender_gift_certificate' => '5.00',
            'gift_certificate_number' => 'missing-' . $number,
        ]);
        self::assertStringContainsString('id="enter-payment" class="modal" role="dialog" aria-modal="true" aria-labelledby="enter-payment-title">', $missing->body);
        self::assertStringContainsString('id="pay-gift-error">that gift certificate number is not on file', $missing->body);
        self::assertStringContainsString('value="missing-' . $number . '"', $missing->body);
        self::assertStringNotContainsString('alert alert-danger', $missing->body);
        $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'gift_certificate',
            'tender_amount' => '5.00',
            'tender_reference' => $number,
        ]);
        $redeemed = $this->client->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $redeemed);
        $left = $this->client->get('/' . self::SLUG . '/pos?gift=' . $number);
        self::assertSees('15.00', $left);
        $open = $this->certificates();
        $this->outstanding($open, $number);
        self::assertSame(1, preg_match(
            '/' . preg_quote($number, '/') . '[\s\S]*?href="(\/' . self::SLUG . '\/pos\/gift\/[0-9a-fA-F-]{36})"/',
            $open->body,
            $slipLink
        ));
        $slip = $this->client->get($slipLink[1]);
        self::assertSees('Balance left', $slip);
        self::assertSees('$15.00', $slip);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $overItem = $this->item($vendor, '20.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $overItem['code']]);
        $overGift = $this->payment([
            'tender_gift_certificate' => '20.00',
            'gift_certificate_number' => $number,
        ]);
        self::assertStringContainsString('id="enter-payment" class="modal" role="dialog" aria-modal="true" aria-labelledby="enter-payment-title">', $overGift->body);
        self::assertStringContainsString('id="pay-gift-error">a gift certificate cannot go below zero', $overGift->body);
        self::assertStringNotContainsString('alert alert-danger', $overGift->body);
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $drawItem = $this->item($vendor, '4.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $drawItem['code']]);
        $drawn = $this->client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'vendor_draw',
            'tender_amount' => '4.00',
            'vendor_id' => $vendor,
        ]);
        self::assertSees('Tender saved.', $drawn);
        $drawPaid = $this->client->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $drawPaid);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $buyerCode = 'b' . $code;
        $buyer = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $buyerCode,
            'first_name' => 'Bea',
            'last_name' => $buyerCode,
            'store_take' => '50',
        ]);
        self::assertSees('Vendor created.', $buyer);
        $buyerId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', $buyerCode);
        $heldItem = $this->item($buyerId, '10.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $heldItem['code']]);
        $noCustomer = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'hold' => '1',
            'tender_cash' => '4.00',
        ]);
        self::assertSees('Choose a customer to hold a layaway.', $noCustomer);
        $withBuyer = $this->client->post('/' . self::SLUG . '/pos/buyer', ['vendor_id' => $buyerId]);
        self::assertSees('Buyer saved.', $withBuyer);
        self::assertSees($buyerCode, $withBuyer);
        $lined = $this->client->post('/' . self::SLUG . '/pos/line-discount', [
            'line_no' => '1',
            'discount_action' => 'apply',
            'discount_kind' => 'amount',
            'discount_value' => '1.00',
            'discount_reason' => 'damaged',
        ]);
        self::assertSees('Line discount saved.', $lined);
        self::assertSees('Line discount', $lined);
        self::assertSees('9.00', $lined);
        $shopper = $this->client->post('/' . self::SLUG . '/pos/customer', [
            'customer_name' => 'Bea ' . $buyerCode,
            'customer_phone' => '',
            'customer_email' => '',
        ]);
        self::assertSees('Customer saved.', $shopper);
        $deposit = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'hold' => '1',
            'tender_cash' => '4.00',
        ]);
        self::assertSees('Layaway held for', $deposit);
        self::assertSees($buyerCode, $deposit);
        self::assertSees('Deposit', $deposit);
        self::assertSees('Cash', $deposit);
        self::assertSees('$4.00', $deposit);
        self::assertSees('Balance', $deposit);
        $stillThere = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $heldItem['code']]);
        self::assertSees('an item is already on a ticket', $stillThere);
        $layaways = $this->client->get('/' . self::SLUG . '/pos/layaways');
        $recalledLayaway = $this->client->post('/' . self::SLUG . '/pos/recall', [
            'ticket_id' => $this->match($layaways->body, '/' . preg_quote($buyerCode, '/') . '[\\s\\S]*?name="ticket_id" value="([0-9a-fA-F-]{36})"/'),
        ]);
        self::assertSees('Ticket recalled.', $recalledLayaway);
        self::assertSees('9.00', $recalledLayaway);
        self::assertSees('Deposit', $recalledLayaway);
        self::assertSees('Cash', $recalledLayaway);
        $finished = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_card' => '5.00',
        ]);
        self::assertSees('Sale posted.', $finished);
        $cleared = $this->client->get('/' . self::SLUG . '/pos/layaways');
        self::assertStringNotContainsString($buyerCode, $cleared->body);
        $locked = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $heldItem['code']]);
        self::assertSees('start a new sale', $locked);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $after = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => 'not-a-item']);
        self::assertSees('that item is not on the floor', $after);
    }

    private function settings(bool $round): void
    {
        $fields = [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
            'floor_days' => '',
            'use_color' => '1',
            'use_category' => '1',
            'use_size' => '1',
            'use_condition' => '1',
            'use_department' => '1',
            'use_brand' => '1',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
        ];
        if ($round) {
            $fields['cash_round_nickel'] = '1';
        }
        $saved = $this->client->post('/' . self::SLUG . '/settings', $fields);
        self::assertSees('Settings saved.', $saved);
    }

    private function openRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $active = $this->client->post($use[1], []);
            self::assertSees('is the active register.', $active);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $opened = $this->client->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ]);
        self::assertSees('Close register', $opened);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $page = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        self::assertSees($code, $page);
        $directory = $this->client->get('/' . self::SLUG . '/parties');

        return HtmlPage::taggedId($directory->body, 'tr', 'vendor', $code);
    }

    private function gift(string $amount, string $tender, string $number = '', string $check = ''): PageResponse
    {
        $fields = [
            'gift_amount' => $amount,
            'gift_tender' => $tender,
        ];
        if ($number !== '') {
            $fields['gift_number'] = $number;
        }
        if ($check !== '') {
            $fields['check_number'] = $check;
        }

        return $this->client->post('/' . self::SLUG . '/pos/gift', $fields);
    }

    private function certificates(): PageResponse
    {
        return $this->client->get('/' . self::SLUG . '/gift-certificates');
    }

    private function outstanding(PageResponse $page, string $number): void
    {
        self::assertSees('Open gift certificates', $page);
        self::assertMatchesRegularExpression(
            '/<tr>[\s\S]*?' . preg_quote($number, '/') . '[\s\S]*?\$20\.00[\s\S]*?\$15\.00[\s\S]*?Print certificate[\s\S]*?<\/tr>/',
            $page->body
        );
    }

    private function giftCertificate(string $number): PageResponse
    {
        return $this->client->get('/' . self::SLUG . '/pos/gift-certificate.json?number=' . rawurlencode($number));
    }

    /**
     * @param array<string, string> $fields
     */
    private function payment(array $fields): PageResponse
    {
        return $this->client->post('/' . self::SLUG . '/pos/pay', $fields);
    }

    private function certificate(PageResponse $slip, string $number, string $amount): void
    {
        self::assertSees('Gift certificate', $slip);
        self::assertSees('Print receipt', $slip);
        self::assertSees('Give this number at the register to redeem.', $slip);
        self::assertSees($amount, $slip);
        if ($number !== '') {
            self::assertSees($number, $slip);
        }
        self::assertStringNotContainsString('id="gift-sell"', $slip->body);
    }

    private function slip(PageResponse $page): void
    {
        $this->certificate($page, '', '10.00');
        self::assertMatchesRegularExpression('/GC-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}/', $page->body);
    }

    /**
     * @return array{code: string, description: string}
     */
    private function item(string $vendorId, string $price): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $description = 'Item ' . $code;
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

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

    /**
     * @return array<string, string>
     */
    private function closeFields(string $cash): array
    {
        return [
            'counted_cash' => $cash,
            'counted_card' => '0.00',
            'counted_checks' => '0.00',
            ...$this->zeroCounts(),
        ];
    }

    private function receiptAction(string $html, string $action): string
    {
        return '/' . self::SLUG . '/pos/receipt/' . $this->match($html, '#/pos/receipt/([0-9a-fA-F-]{36})/' . $action . '#') . '/' . $action;
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

    private function lastMatch(string $html, string $pattern): string
    {
        self::assertGreaterThan(0, preg_match_all($pattern, $html, $match));
        $last = $match[1][array_key_last($match[1])];
        self::assertIsString($last);

        return $last;
    }
}
