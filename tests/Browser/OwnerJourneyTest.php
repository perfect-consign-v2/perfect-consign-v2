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

        self::assertSees('<h1>Book</h1>', $book);
    }

    public function testTheOwnerPostsASaleAndSeesItOnJournals(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $code = $this->vendor();
        $form = $this->session->open('/' . ProbeMall::SLUG . '/sale');
        $saleId = HtmlPage::inputValue($form, 'sale_id');
        $journal = $this->session->submit('/' . ProbeMall::SLUG . '/sale', [
            'sale_id' => $saleId,
            'vendor_id' => HtmlPage::option($form, 'vendor_id', $code),
            'tender' => 'cash',
            'amount' => '25.00',
            'commission' => '5.00',
            'tax' => '0.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSees('<p>Sale</p>', $journal);
        self::assertSees($saleId, $journal);
        self::assertSees($saleId, $this->session->open('/' . ProbeMall::SLUG . '/journals'));
    }

    public function testTheOwnerPostsRentAndSeesTheCharge(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $vendor = $this->vendor();
        $booth = $this->booth();
        $parties = $this->session->html();
        $this->session->submit('/' . ProbeMall::SLUG . '/assignments', [
            'booth_id' => HtmlPage::option($parties, 'assign_booth', $booth),
            'vendor_id' => HtmlPage::option($parties, 'assign_vendor', $vendor),
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $this->session->submit('/' . ProbeMall::SLUG . '/rates', [
            'booth_id' => HtmlPage::option($parties, 'rate_booth', $booth),
            'month_start' => ProbeMall::POSTING_DATE,
            'amount' => '100.00',
        ]);
        $this->session->open('/' . ProbeMall::SLUG . '/rent');
        $journal = $this->session->submit('/' . ProbeMall::SLUG . '/rent/charge', [
            'booth_id' => HtmlPage::option($parties, 'assign_booth', $booth),
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);

        self::assertSees('Rent charge', $journal);
    }

    public function testTheOwnerSeesARefusedPayout(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $code = $this->vendor();
        $parties = $this->session->html();
        $vendorId = HtmlPage::option($parties, 'assign_vendor', $code);
        $form = $this->session->open('/' . ProbeMall::SLUG . '/payout?vendor=' . rawurlencode($vendorId));
        self::assertSees('Open payable 0.00', $form);
        $refused = $this->session->submit('/' . ProbeMall::SLUG . '/payout', [
            'vendor_id' => $vendorId,
            'month' => ProbeMall::MONTH,
            'posting_date' => ProbeMall::POSTING_DATE,
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
        $edit = HtmlPage::articleLink($page, $name);
        $update = preg_replace('#/edit$#', '', $edit);
        self::assertIsString($update);
        $this->session->open($edit);
        $renamedPage = $this->session->submit($update, [
            'name' => $renamed,
        ]);
        self::assertSees($renamed, $renamedPage);
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

        self::assertSees('brands_name_key', $duplicate);
    }

    public function testTheOwnerSignsOut(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $confirm = $this->session->submit('/' . ProbeMall::SLUG . '/logout', []);
        self::assertSees('<h1>Sign out</h1>', $confirm);
        $home = $this->session->submit('/' . ProbeMall::SLUG . '/logout/confirm', []);

        self::assertSees('Sign in', $home);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $this->session->open('/' . ProbeMall::SLUG . '/parties');
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }

    private function booth(): string
    {
        $code = 'b' . bin2hex(random_bytes(4));
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/booths', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }
}
