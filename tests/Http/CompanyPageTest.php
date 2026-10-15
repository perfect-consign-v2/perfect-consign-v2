<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class CompanyPageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
        $this->client->ensurePeriod(self::SLUG);
    }

    public function testTheOwnerSavesCompanyInfoAndReceiptsPrintIt(): void
    {
        $name = 'Probe Mercantile ' . bin2hex(random_bytes(3));
        $phone = '541-555-' . (string) random_int(1000, 9999);
        $fields = [
            'store_name' => $name,
            'street' => '123 Main Street',
            'street2' => 'Suite 4',
            'city' => 'Springfield',
            'state' => 'OR',
            'postal' => '97477',
            'phone' => $phone,
            'email' => 'owner@store.test',
        ];
        $blank = $this->client->post('/' . self::SLUG . '/company', ['store_name' => ' '] + $fields);
        self::assertSees('Enter a store name.', $blank);
        $kept = $this->client->get('/' . self::SLUG . '/company');
        self::assertSees('value="' . ProbeMall::DISPLAY_NAME . '"', $kept);
        $badEmail = $this->client->post('/' . self::SLUG . '/company', ['email' => 'not-an-email'] + $fields);
        self::assertSees('that email is not an email address', $badEmail);
        $saved = false;
        try {
            $page = $this->client->post('/' . self::SLUG . '/company', $fields);
            self::assertSees('Company saved.', $page);
            self::assertSees($name, $page);
            self::assertSees($phone, $page);
            self::assertSees('123 Main Street', $page);
            self::assertSees('Suite 4', $page);
            $saved = true;
            $this->openRegister();
            $this->assertSaleReceipt($name, $phone);
            $this->assertRentReceipt($name, $phone);
        } finally {
            if ($saved) {
                $restored = $this->client->post('/' . self::SLUG . '/company', [
                    'store_name' => ProbeMall::DISPLAY_NAME,
                    'street' => '',
                    'street2' => '',
                    'city' => '',
                    'state' => '',
                    'postal' => '',
                    'phone' => '',
                    'email' => '',
                ]);
                self::assertSees('Company saved.', $restored);
            }
        }
    }

    public function testACashierCannotOpenTheCompanyPage(): void
    {
        $username = 'c' . bin2hex(random_bytes(4));
        $added = $this->client->post('/' . self::SLUG . '/staff', [
            'display_name' => 'Company Cashier',
            'username' => $username,
            'password' => 'cashier-pass',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Cashier added.', $added);
        $cashier = self::client();
        $cashier->signIn(self::SLUG, $username, 'cashier-pass');
        $page = $cashier->get('/' . self::SLUG . '/company');

        self::assertSame(404, $page->status);
    }

    private function assertSaleReceipt(string $name, string $phone): void
    {
        $vendor = $this->vendor();
        $directory = $this->client->get('/' . self::SLUG . '/parties');
        $vendorId = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $vendor);
        $tag = 't' . bin2hex(random_bytes(4));
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag,
            'description' => 'Coat ' . $tag,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '10.00',
            'quantity' => '1',
            'barcode' => 'BC-' . $tag,
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => 'BC-' . $tag]);
        self::assertSame(1, preg_match('/register-due">\$([0-9]+\.[0-9]{2})/', $cart->body, $due));
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_cash' => $due[1],
            'cash_received' => $due[1],
        ]);
        self::assertSame(1, preg_match('#/pos/receipt/([0-9a-f-]{36})#', $paid->body, $journalId));
        $receipt = $this->client->get('/' . self::SLUG . '/pos/receipt/' . $journalId[1]);
        $this->assertHeader($receipt->body, $name, $phone);
    }

    private function assertRentReceipt(string $name, string $phone): void
    {
        $vendor = $this->vendor();
        $booth = $this->booth();
        $parties = $this->client->get('/' . self::SLUG . '/parties');
        $booths = $this->client->get('/' . self::SLUG . '/booths');
        $vendorId = HtmlPage::taggedId($parties->body, 'tr', 'vendor', $vendor);
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $booth);
        $this->client->post('/' . self::SLUG . '/rates', [
            'booth_id' => $boothId,
            'amount' => '40.00',
        ]);
        $this->client->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $rent = $this->client->get('/' . self::SLUG . '/rent');
        self::assertSame(1, preg_match('/datetime="(\d{4}-\d{2}-\d{2})"/', $rent->body, $today));
        $charged = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => $today[1],
        ]);
        self::assertSees('Rent charged.', $charged);
        $paid = $this->client->post('/' . self::SLUG . '/rent/payment', [
            'vendor_id' => $vendorId,
            'booth_id' => $boothId,
            'amount' => '10.00',
            'tender_cash' => '10.00',
            'cash_tendered' => '10.00',
        ]);
        $this->assertHeader($paid->body, $name, $phone);
        self::assertSees('Rent receipt', $paid);
    }

    private function assertHeader(string $html, string $name, string $phone): void
    {
        self::assertStringContainsString('class="slip-store">' . $name . '<', $html);
        self::assertStringContainsString('123 Main Street', $html);
        self::assertStringContainsString('Suite 4', $html);
        self::assertStringContainsString('Springfield, OR 97477', $html);
        self::assertStringContainsString($phone, $html);
        self::assertStringContainsString('owner@store.test', $html);
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
        $pattern = '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#';
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
        $page = $this->client->post('/' . self::SLUG . '/vendors', [
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
        $page = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }
}
