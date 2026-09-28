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
        self::assertSees('<h1>Book</h1>', $signedIn);
    }

    public function testTheOwnerPostsASaleAndSeesTheJournal(): void
    {
        $code = $this->vendor();
        $form = $this->client->get('/' . ProbeMall::SLUG . '/sale');
        $saleId = HtmlPage::inputValue($form->body, 'sale_id');
        $vendorId = HtmlPage::option($form->body, 'vendor_id', $code);
        $journal = $this->client->post('/' . ProbeMall::SLUG . '/sale', [
            'sale_id' => $saleId,
            'vendor_id' => $vendorId,
            'tender' => 'cash',
            'amount' => '25.00',
            'commission' => '5.00',
            'tax' => '0.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSame(200, $journal->status);
        self::assertSees('<p>Sale</p>', $journal);
        self::assertSees($saleId, $journal);
        $journals = $this->client->get('/' . ProbeMall::SLUG . '/journals');
        self::assertSees($saleId, $journals);
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
        $edit = HtmlPage::articleLink($page->body, $name);
        $update = preg_replace('#/edit$#', '', $edit);
        self::assertIsString($update);
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
        self::assertSees('brands_name_key', $duplicate);
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
        $saved = $this->client->post('/' . ProbeMall::SLUG . '/settings', [
            'time_zone' => 'America/Chicago',
            'booth_width_ft' => '12',
            'booth_depth_ft' => '8',
            'floor_width_ft' => '100',
            'floor_depth_ft' => '80',
            'default_store_take' => '0',
        ]);
        self::assertSees('America/Chicago', $saved);
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
        self::assertSees('A vendor who owes the store cannot be made inactive.', $refused);
        self::assertSees('Make inactive', $refused);
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
