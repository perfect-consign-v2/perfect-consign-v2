<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
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

    public function testTheFloorVoidsReturnsAndParks(): void
    {
        $this->settings(false);
        $this->openRegister();
        $vendor = $this->vendor();
        $first = $this->piece($vendor, '10.00');
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
            '/id="register-' . $registerId . '".*?Drawer expected ([0-9]+\.[0-9]{2})/s'
        );
        $blocked = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/close', $this->closeFields($expected));
        self::assertSees('close is refused while a ticket is open', $blocked);
        $parked = $this->client->post('/' . self::SLUG . '/pos/park', []);
        self::assertSees('name="ticket_id"', $parked);
        $ticketId = $this->match($parked->body, '/name="ticket_id" value="([0-9a-fA-F-]{36})"/');
        $recalled = $this->client->post('/' . self::SLUG . '/pos/recall', ['ticket_id' => $ticketId]);
        self::assertSees($first['description'], $recalled);
        $second = $this->piece($vendor, '4.00');
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
        self::assertSees('Piece returned.', $returned);
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
        self::assertSees('This ticket was voided.', $voided);
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
        $first = $this->piece($vendor, '10.00');
        $second = $this->piece($vendor, '30.00');
        $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $first['code']]);
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $second['code']]);
        self::assertSees('5.00', $cart);
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
        self::assertSees('Piece returned.', $returned);
        self::assertSees($second['description'], $returned);
        $third = $this->piece($vendor, '8.00');
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

    public function testTheBooksPagesPost(): void
    {
        $map = $this->client->get('/' . self::SLUG . '/map');
        self::assertSees('Booth map', $map);
        $editor = $this->client->get('/' . self::SLUG . '/map/edit');
        self::assertSees('Edit floor map', $editor);
        $floorName = 'Loft ' . bin2hex(random_bytes(2));
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

        $export = $this->client->post('/' . self::SLUG . '/export', [
            'from' => '2020-01-01',
            'to' => '2035-12-31',
            'system' => 'quickbooks',
            'tax_rate' => 'Tax Exempt',
        ]);
        self::assertSame(200, $export->status);
        self::assertNotSame('', $export->body);

        $adjustmentForm = $this->client->get('/' . self::SLUG . '/adjustment');
        self::assertSees('Adjustment', $adjustmentForm);
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
        self::assertSees('Opening balance', $opening);
        $refused = $this->client->post('/' . self::SLUG . '/opening', ['posting_date' => $today]);
        self::assertSees('an opening is available only while the book has no other journal', $refused);

        $this->client->post('/' . self::SLUG . '/periods', [
            'starts_on' => '2019-01-01',
            'ends_on' => '2019-12-31',
        ]);
        $close = $this->client->get('/' . self::SLUG . '/close');
        self::assertSees('Year-end close', $close);
        $openYear = '/<tr>\s*<td>2019-01-01<\/td>\s*<td>2019-12-31<\/td>\s*<td>open<\/td>.*?name="period_id" value="([0-9a-fA-F-]{36})"/s';
        if (preg_match($openYear, $close->body, $period) === 1) {
            $closed = $this->client->post('/' . self::SLUG . '/close', [
                'period_id' => $period[1],
                'ends_on' => '2019-12-31',
            ]);
            self::assertSees('Period closed.', $closed);
        }

        $backup = $this->client->post('/' . self::SLUG . '/backup', ['return' => 'settings'], 90);
        self::assertSame(200, $backup->status);
        self::assertNotSame('', $backup->body);
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

    /**
     * @return array{code: string, description: string}
     */
    private function piece(string $vendorId, string $price): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $description = 'Piece ' . $code;
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
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
}
