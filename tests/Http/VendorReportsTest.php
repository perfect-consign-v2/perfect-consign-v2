<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * The vendor register, rent payments, and the rent history card, as staff
 * see them: each filter, print and CSV, and the same numbers on all three.
 */
final class VendorReportsTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const MISSING = '01900000-0000-7000-8000-00000000c0ff';

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

    public function testTheRegisterPaymentsAndHistoryAgreeForOneVendor(): void
    {
        $tag = bin2hex(random_bytes(3));
        $code = 'vr' . $tag;
        $booth = 'br' . $tag;
        [$vendorId, $boothId] = $this->rented($code, $booth);
        $this->openRegister();
        $rent = $this->client->get('/' . self::SLUG . '/rent');
        self::assertSame(1, preg_match('/<time datetime="(\d{4}-\d{2}-\d{2})">/', $rent->body, $today));
        $month = date('F Y', (int) strtotime($today[1]));
        $charged = $this->client->post('/' . self::SLUG . '/rent/charge', self::chargeButton($rent->body, $booth));
        self::assertSees('Rent charged.', $charged);
        $paid = $this->client->post('/' . self::SLUG . '/rent/payment', [
            'vendor_id' => $vendorId,
            'amount' => '25.00',
            'tender_card' => '25.00',
        ]);
        self::assertSees('Rent receipt', $paid);
        self::assertSame(1, preg_match('#/rent/receipt/([0-9a-fA-F-]{36})#', $paid->effectiveUrl, $receipt));

        $register = $this->client->get('/' . self::SLUG . '/reports/vendor-register?' . http_build_query(['vendor' => $vendorId]));
        self::assertSees('Vendor register', $register);
        self::assertSees('Opening balance', $register);
        self::assertSees('Rent for ' . $month, $register);
        self::assertSees('Rent payment, receipt #', $register);
        self::assertSees('They owe in rent $15.00', $register);
        self::assertSees('Net -$15.00', $register);
        self::assertSees('data-print', $register);
        $boothOnly = $this->client->get('/' . self::SLUG . '/reports/vendor-register?' . http_build_query(['vendor' => $vendorId, 'booth' => $boothId]));
        self::assertSees('Rent for ' . $month, $boothOnly);
        self::assertSees('Net -$15.00', $boothOnly);
        $lastMonth = $this->client->get('/' . self::SLUG . '/reports/vendor-register?' . http_build_query(['vendor' => $vendorId, 'range' => 'last_month']));
        self::assertSees('Nothing happened in this range.', $lastMonth);
        $csv = $this->client->get('/' . self::SLUG . '/reports/vendor-register.csv?' . http_build_query(['vendor' => $vendorId, 'range' => 'this_month']));
        self::assertStringContainsString('Date,"What happened",Booth,Debit,Credit,Balance', $csv->body);
        self::assertStringContainsString('-15.00', $csv->body);

        $payments = $this->client->get('/' . self::SLUG . '/reports/rent-payments?' . http_build_query(['vendor' => $vendorId]));
        self::assertSees('Rent payments', $payments);
        self::assertSees('/rent/receipt/' . strtolower($receipt[1]), $payments);
        self::assertSees('Card', $payments);
        self::assertSees(date('M Y', (int) strtotime($today[1])), $payments);
        self::assertSees('1 payment, not counting voided', $payments);
        self::assertSees('Card $25.00', $payments);
        $byCode = $this->client->get('/' . self::SLUG . '/reports/rent-payments?' . http_build_query(['q' => $code]));
        self::assertSees('Card $25.00', $byCode);
        $cashOnly = $this->client->get('/' . self::SLUG . '/reports/rent-payments?' . http_build_query(['vendor' => $vendorId, 'paid_by' => 'cash']));
        self::assertSees('No rent was paid in this range.', $cashOnly);
        $paymentsCsv = $this->client->get('/' . self::SLUG . '/reports/rent-payments.csv?' . http_build_query(['vendor' => $vendorId, 'range' => 'this_month']));
        self::assertStringContainsString('Date,Receipt,Vendor,Booth,Months,"How paid",Register,Cashier,Amount', $paymentsCsv->body);
        self::assertStringContainsString('25.00', $paymentsCsv->body);

        $page = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSees('Rent history', $page);
        self::assertSees('$40.00 charged', $page);
        self::assertSees('$25.00 card (receipt #', $page);
        self::assertSees('$15.00 owed', $page);
        self::assertSees('/reports/vendor-register?vendor=' . $vendorId, $page);
        $all = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId . '?history=all');
        self::assertSees('Show recent months', $all);
    }

    public function testFiltersAreRefusedInPlainWords(): void
    {
        $register = '/' . self::SLUG . '/reports/vendor-register?';
        $payments = '/' . self::SLUG . '/reports/rent-payments?';
        self::assertSees('Choose a vendor to see their register.', $this->client->get('/' . self::SLUG . '/reports/vendor-register'));
        self::assertSees('Choose a vendor.', $this->client->get($register . http_build_query(['vendor' => 'nobody'])));
        self::assertSees('Choose a vendor.', $this->client->get($register . http_build_query(['vendor' => self::MISSING])));
        self::assertSees('Choose a booth.', $this->client->get($payments . http_build_query(['booth' => 'B1'])));
        self::assertSees('Choose how it was paid.', $this->client->get($payments . http_build_query(['paid_by' => 'gold'])));
        self::assertSees('That filter is not on this page.', $this->client->get($payments . http_build_query(['group' => 'day'])));
        self::assertSees('To is before From.', $this->client->get($payments . http_build_query(['from' => '2026-06-30', 'to' => '2026-06-01'])));
        $refusedCsv = $this->client->get('/' . self::SLUG . '/reports/rent-payments.csv?' . http_build_query(['paid_by' => 'gold']));
        self::assertSees('Choose how it was paid.', $refusedCsv);
        $refusedRegisterCsv = $this->client->get('/' . self::SLUG . '/reports/vendor-register.csv?' . http_build_query(['vendor' => 'nobody']));
        self::assertSees('Choose a vendor.', $refusedRegisterCsv);
        $reports = $this->client->get('/' . self::SLUG . '/reports');
        self::assertSees('/reports/rent-payments', $reports);
        self::assertSees('/reports/vendor-register', $reports);
    }

    public function testOnlyStaffWhoReadReportsSeeThem(): void
    {
        $seed = ProbeSurfaceSeed::ensure($this->client);
        $cashier = self::client();
        $cashier->signIn(self::SLUG, $seed->cashierUsername, $seed->cashierPassword);
        $vendor = self::client();
        $vendor->signIn(self::SLUG, $seed->vendorUsername, $seed->vendorPassword);
        foreach (['rent-payments', 'rent-payments.csv', 'vendor-register?vendor=' . $seed->vendorId, 'vendor-register.csv?vendor=' . $seed->vendorId] as $page) {
            foreach (['cashier' => $cashier, 'vendor' => $vendor] as $who => $client) {
                $seen = $client->get('/' . self::SLUG . '/reports/' . $page);
                self::assertStringNotContainsString('Opening balance', $seen->body, $who . ' saw ' . $page);
                self::assertStringNotContainsString('not counting voided', $seen->body, $who . ' saw ' . $page);
                self::assertStringNotContainsString('Date,', $seen->body, $who . ' downloaded ' . $page);
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function rented(string $code, string $booth): array
    {
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Rhea',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/vendors')->body, 'tr', 'vendor', $code);
        $made = $this->client->post('/' . self::SLUG . '/booths', ['code' => $booth, 'name' => 'Rent ' . $booth]);
        self::assertSees('Booth created.', $made);
        $boothId = HtmlPage::taggedId($made->body, 'tr', 'booth', $booth);
        $rent = $this->client->get('/' . self::SLUG . '/rent');
        self::assertSame(1, preg_match('/<time datetime="(\d{4}-\d{2})-\d{2}">/', $rent->body, $month));
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => $vendorId,
            'booth_id' => $boothId,
            'starts_on' => $month[1] . '-01',
            'rent' => '40.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);

        return [$vendorId, $boothId];
    }

    private function openRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (preg_match('#/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/close#', $page->body) === 1) {
            return;
        }
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $match) !== 1) {
            $created = $this->client->post('/' . self::SLUG . '/registers', ['name' => 'Rent ' . bin2hex(random_bytes(2))]);
            self::assertSees('Register added.', $created);
            self::assertSame(1, preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#', $created->body, $match));
        }
        $fields = ['cashier' => ProbeMall::USERNAME, 'opening_float' => '0.00'];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        self::assertSees('Register opened.', $this->client->post($match[1], $fields));
    }
}
