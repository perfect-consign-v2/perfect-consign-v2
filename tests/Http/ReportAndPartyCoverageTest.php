<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ReportAndPartyCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const MISSING = '01900000-0000-7000-8000-00000000c0ee';

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

    public function testReportsOpenForAMonthARangeAndASavedSheet(): void
    {
        $home = $this->client->get('/' . self::SLUG . '/reports');
        self::assertSees('Reports', $home);
        self::assertSees('Report builder', $home);

        $tax = $this->client->get('/' . self::SLUG . '/reports/sales-tax?month=2020-01');
        self::assertSees('Monthly sales tax', $tax);
        self::assertSees('2020-01', $tax);
        self::assertSees('Download CSV', $tax);
        $printed = $this->client->get('/' . self::SLUG . '/reports/sales-tax?month=2020-01&print=1');
        self::assertSees('Monthly sales tax', $printed);
        self::assertStringNotContainsString('Download CSV', $printed->body);
        $fallback = $this->client->get('/' . self::SLUG . '/reports/sales-tax?month=June');
        self::assertSees(date('Y-m'), $fallback);

        $from = '2026-06-10';
        $sales = $this->client->get('/' . self::SLUG . '/reports/sales?from=' . $from . '&to=2026-06-01&group=vendor');
        self::assertSees('Sales', $sales);
        foreach (['day', 'tender', 'cashier', 'category', 'nope'] as $group) {
            $grouped = $this->client->get('/' . self::SLUG . '/reports/sales?from=' . $from . '&to=' . $from . '&group=' . $group);
            self::assertSame(200, $grouped->status);
            self::assertSees('Sales', $grouped);
        }
        $open = $this->client->get('/' . self::SLUG . '/reports/sales?from=June&to=July');
        self::assertSees('Sales', $open);
        $payouts = $this->client->get('/' . self::SLUG . '/reports/payouts?from=' . $from . '&to=' . $from);
        self::assertSees('Payout register', $payouts);

        $builder = $this->client->get('/' . self::SLUG . '/reports/build');
        self::assertSees('Report builder', $builder);
        $reserved = $this->client->post('/' . self::SLUG . '/reports/build', [
            'name' => 'Sales Tax',
            'source' => 'sales',
            'grouped' => 'day',
        ]);
        self::assertSees('that report name is reserved or empty', $reserved);
        $catalog = $this->client->post('/' . self::SLUG . '/reports/build', [
            'name' => 'Rent by day',
            'source' => 'rent',
            'grouped' => 'day',
        ]);
        self::assertSees('that report is not in the catalog', $catalog);

        $tag = bin2hex(random_bytes(3));
        $savedId = '';
        foreach ([
            ['Sales ' . $tag, 'sales', 'none'],
            ['Rent ' . $tag, 'rent', 'none'],
            ['Paid ' . $tag, 'payouts', 'none'],
            ['Stored ' . $tag, 'stored_value', 'none'],
        ] as $spec) {
            $stored = $this->client->post('/' . self::SLUG . '/reports/build', [
                'name' => $spec[0],
                'source' => $spec[1],
                'grouped' => $spec[2],
            ]);
            self::assertSees('Report saved.', $stored);
            self::assertSees($spec[0], $stored);
            if ($savedId === '') {
                $savedId = $this->match($stored->body, '#/reports/saved/([0-9a-fA-F-]{36})">Sales ' . $tag . '#');
            }
        }
        $sheet = $this->client->get('/' . self::SLUG . '/reports/saved/' . $savedId);
        self::assertSees('Sales ' . $tag, $sheet);
        foreach (['Rent ' . $tag, 'Paid ' . $tag, 'Stored ' . $tag] as $name) {
            $id = $this->match(
                $this->client->get('/' . self::SLUG . '/reports/build')->body,
                '#/reports/saved/([0-9a-fA-F-]{36})">' . preg_quote($name, '#') . '#'
            );
            $opened = $this->client->get('/' . self::SLUG . '/reports/saved/' . $id);
            self::assertSees($name, $opened);
        }
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/reports/saved/' . self::MISSING)->status);
    }

    public function testReportSpreadsheetsDownload(): void
    {
        $pages = [
            '/reports/sales-tax.csv?month=2020-01' => 'section',
            '/reports/sales-tax.csv?month=June' => 'section',
            '/reports/sales.csv?from=2026-06-02&to=2026-06-01&group=nope' => 'label',
            '/reports/sales.csv?from=June&to=July&group=hour' => 'label',
            '/reports/rent.csv' => 'booth',
            '/reports/payouts.csv?from=2026-06-01&to=2026-06-02' => 'paid_on',
            '/reports/stored-value.csv' => 'kind',
            '/reports/expiring.csv' => 'code',
            '/reports/donated.csv?from=2026-06-01&to=2026-06-01' => 'posted_on',
            '/reports/sell-through.csv?from=2026-06-02&to=2026-06-01' => 'category',
        ];
        foreach ($pages as $path => $column) {
            $page = $this->client->get('/' . self::SLUG . $path);
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString($column, $page->body, $path);
        }
    }

    public function testPartyLinksRolesAndTheDeleteBranches(): void
    {
        $tag = bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'p' . $tag,
            'first_name' => 'Pat',
            'last_name' => $tag,
        ]);
        self::assertSees('Vendor created.', $created);
        $vendorId = HtmlPage::pathId($created->body, self::SLUG . '/vendors');
        $partyId = $this->partyId('Pat ' . $tag);
        $record = $this->client->get('/' . self::SLUG . '/parties/' . $partyId);
        self::assertSees('p' . $tag, $record);

        $customer = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/customer-role', []);
        self::assertSees('Customer role added.', $customer);
        $stillVendor = $this->client->get('/' . self::SLUG . '/parties/' . $partyId);
        self::assertSees('p' . $tag, $stillVendor);

        $shopper = 'Sam ' . $tag;
        $added = $this->client->post('/' . self::SLUG . '/customers', [
            'customer_name' => $shopper,
            'customer_phone' => '',
            'customer_email' => '',
        ]);
        self::assertSees('Customer created.', $added);
        $shopperParty = $this->partyId($shopper);
        $shopperPage = $this->client->get('/' . self::SLUG . '/parties/' . $shopperParty);
        self::assertSees($shopper, $shopperPage);
        self::assertStringContainsString('/customers/' . $shopperParty, $shopperPage->body);

        $waiting = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Wynn',
            'last_name' => $tag,
            'email' => 'wynn-' . $tag . '@example.test',
            'phone' => '555-0140',
        ]);
        self::assertSees('Added to the wait list.', $waiting);
        $waitParty = $this->partyId('Wynn ' . $tag);
        $waitPage = $this->client->get('/' . self::SLUG . '/parties/' . $waitParty);
        self::assertStringContainsString('/wait-list/' . $waitParty, $waitPage->body);

        self::assertSame(404, $this->client->get('/' . self::SLUG . '/parties/' . self::MISSING)->status);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/vendors/' . self::MISSING . '/edit')->status);
        self::assertSame(
            404,
            $this->client->post('/' . self::SLUG . '/vendors/' . self::MISSING . '/customer-role', [])->status
        );

        $other = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'q' . $tag,
            'first_name' => 'Quin',
            'last_name' => $tag,
        ]);
        self::assertSees('Vendor created.', $other);
        $otherId = HtmlPage::pathId($other->body, self::SLUG . '/vendors');
        $duplicate = $this->client->post('/' . self::SLUG . '/vendors/' . $otherId, [
            'code' => 'p' . $tag,
            'first_name' => 'Quin',
            'last_name' => $tag,
        ]);
        self::assertSees('That code is already used.', $duplicate);

        $held = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => 'i' . $tag,
            'description' => 'Held ' . $tag,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '4.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $held);
        $blocked = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/delete', []);
        self::assertSees('permission denied for table vendors', $blocked);
        self::assertSees('p' . $tag, $blocked);

        $removed = $this->client->post('/' . self::SLUG . '/vendors/' . $otherId . '/delete', []);
        self::assertSees('permission denied for table vendors', $removed);
        self::assertSees('q' . $tag, $removed);
        $gone = $this->client->post('/' . self::SLUG . '/vendors/' . self::MISSING . '/delete', []);
        self::assertSees('permission denied for table vendors', $gone);
        self::assertSees('Vendors', $gone);
    }

    private function partyId(string $name): string
    {
        $page = $this->client->get('/' . self::SLUG . '/parties');

        return $this->match(
            $page->body,
            '#/parties/([0-9a-fA-F-]{36})">' . preg_quote($name, '#') . '#'
        );
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern . "\n" . substr($html, 0, 400));

        return $match[1];
    }
}
