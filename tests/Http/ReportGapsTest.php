<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ReportGapsTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testNamedReportGapsOpenFromTheMenu(): void
    {
        $slug = ProbeMall::SLUG;
        $menu = $this->client->get('/' . $slug . '/reports');
        self::assertSame(200, $menu->status);
        self::assertSees('Hourly sales', $menu);
        self::assertSees('Category sell-through', $menu);
        self::assertSees('Expiring items', $menu);
        self::assertSees('Donated items', $menu);

        $hourly = $this->client->get('/' . $slug . '/reports/sales?group=hour');
        self::assertSame(200, $hourly->status);
        self::assertSees('Sales', $hourly);

        $sell = $this->client->get('/' . $slug . '/reports/sell-through');
        self::assertSame(200, $sell->status);
        self::assertSees('Category sell-through', $sell);

        $expiring = $this->client->get('/' . $slug . '/reports/expiring');
        self::assertSame(200, $expiring->status);
        self::assertSees('Expiring items', $expiring);

        $donated = $this->client->get('/' . $slug . '/reports/donated');
        self::assertSame(200, $donated->status);
        self::assertSees('Donated items', $donated);

        self::assertSame(200, $this->client->get('/' . $slug . '/reports/expiring.csv')->status);
        self::assertSame(200, $this->client->get('/' . $slug . '/reports/donated.csv')->status);
        self::assertSame(200, $this->client->get('/' . $slug . '/reports/sell-through.csv')->status);
    }

    public function testTwelveDollarsAHeaderAnEmptyRangeAndABadMonth(): void
    {
        $slug = ProbeMall::SLUG;
        $tag = bin2hex(random_bytes(3));
        $vendor = $this->client->post('/' . $slug . '/vendors', [
            'code' => 'r' . $tag,
            'first_name' => 'Rae',
            'last_name' => $tag,
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::pathId($vendor->body, $slug . '/vendors');
        $this->openRegister($slug);
        $this->sell($slug, $vendorId, 'k' . $tag, '12.00');

        $sales = $this->client->get('/' . $slug . '/reports/sales?range=today');
        self::assertSees('$12.00', $sales);
        self::assertSees('Receipts', $sales);
        self::assertStringContainsString('sales.csv?group=day&amp;range=today', $sales->body);
        $file = $this->client->get('/' . $slug . '/reports/sales.csv?group=day&range=today');
        self::assertStringContainsString('12.00', $file->body);
        self::assertStringContainsString('Receipts', $file->body);

        $empty = $this->client->get('/' . $slug . '/reports/payouts?from=2099-01-01&to=2099-01-31');
        self::assertSees('No payments in this range.', $empty);
        self::assertStringContainsString('payouts.csv?from=2099-01-01&amp;to=2099-01-31', $empty->body);

        $month = $this->client->get('/' . $slug . '/reports/sales-tax?month=June');
        self::assertSees('Enter a real month.', $month);
        self::assertStringNotContainsString('Gross', $month->body);
        self::assertStringNotContainsString('Still owe', $month->body);
        self::assertStringNotContainsString('Tax collected', $month->body);
    }

    private function sell(string $slug, string $vendorId, string $code, string $price): void
    {
        $created = $this->client->post('/' . $slug . '/inventory', [
            'code' => $code,
            'description' => 'Report ' . $code,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);
        $this->client->post('/' . $slug . '/pos/new', []);
        $this->client->post('/' . $slug . '/pos/discard', []);
        $cart = $this->client->post('/' . $slug . '/pos/scan', ['scan' => $code]);
        self::assertSees($code, $cart);
        self::assertSame(1, preg_match('/class="register-due">\\$([0-9.]+)</', $cart->body, $due));
        $paid = $this->client->post('/' . $slug . '/pos/pay', [
            'tender_cash' => $due[1],
            'cash_received' => $due[1],
        ]);
        self::assertSees('Sale posted.', $paid);
        $this->client->post('/' . $slug . '/pos/new', []);
    }

    private function openRegister(string $slug): void
    {
        $page = $this->client->get('/' . $slug . '/registers');
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        self::assertSame(1, preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $open));
        $fields = [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
        ];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        $this->client->post($open[1], $fields);
    }
}
