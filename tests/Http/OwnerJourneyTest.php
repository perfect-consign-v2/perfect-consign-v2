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
        $this->client->post('/' . ProbeMall::SLUG . '/assignments', [
            'booth_id' => HtmlPage::option($parties->body, 'assign_booth', $booth),
            'vendor_id' => HtmlPage::option($parties->body, 'assign_vendor', $vendor),
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $this->client->post('/' . ProbeMall::SLUG . '/rates', [
            'booth_id' => HtmlPage::option($parties->body, 'rate_booth', $booth),
            'month_start' => ProbeMall::POSTING_DATE,
            'amount' => '100.00',
        ]);
        $journal = $this->client->post('/' . ProbeMall::SLUG . '/rent/charge', [
            'booth_id' => HtmlPage::option($parties->body, 'assign_booth', $booth),
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSame(200, $journal->status);
        self::assertSees('Rent charge', $journal);
    }

    public function testAPayoutWithNothingOpenIsRefused(): void
    {
        $code = $this->vendor();
        $parties = $this->client->get('/' . ProbeMall::SLUG . '/parties');
        $vendorId = HtmlPage::option($parties->body, 'assign_vendor', $code);
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

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $page = $this->client->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'name' => $code,
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
