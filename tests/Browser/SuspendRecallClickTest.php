<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Browser;

use PerfectConsign\Tests\Support\ChromeProbe;
use PerfectConsign\Tests\Support\ChromeSession;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cashier click path: Suspend Sale modal → Suspended Sales → Recall.
 * HTTP posts alone miss ticket-commands / register-swap; this must stay green.
 */
#[Group('browser')]
final class SuspendRecallClickTest extends WebTestCase
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

    public function testSuspendModalAndRecallClickReturnTheCart(): void
    {
        $this->session->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->openRegister();
        $vendor = $this->vendor();
        $vendorId = HtmlPage::taggedId($this->session->html(), 'article', 'vendor', $vendor);
        $tag = 't' . bin2hex(random_bytes(4));
        $description = 'Hold ' . $tag;
        $this->session->open('/' . ProbeMall::SLUG . '/inventory/new');
        $created = $this->session->submit('/' . ProbeMall::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '7.00',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);
        $this->openRegister();
        $pos = $this->session->open('/' . ProbeMall::SLUG . '/pos');
        self::assertSees('id="scan"', $pos);
        if (str_contains($pos, 'data-open-modal="clear-cart"')) {
            $this->session->clickSelector('[data-open-modal="clear-cart"]', false);
            $pos = $this->session->clickUntilSees('#clear-cart button[type="submit"]', 'Cart is empty');
        }
        // Native submit paints HTML; scan's ticket-commands JSON path does not re-draw the cart.
        $cart = $this->session->submit('/' . ProbeMall::SLUG . '/pos/scan', ['scan' => $tag]);
        self::assertSees($description, $cart);
        self::assertSees('data-open-modal="suspend-sale"', $cart);
        self::assertSees('Name this hold (optional)', $cart);

        $this->session->clickSelector('[data-open-modal="suspend-sale"]', false);
        $parked = $this->session->clickUntilSees(
            '#suspend-sale button[type="submit"]',
            'Cart is empty',
        );
        self::assertSees('Cart is empty', $parked);

        $suspended = $this->session->open('/' . ProbeMall::SLUG . '/pos/suspended');
        self::assertSees($description, $suspended);
        self::assertSees('Recall', $suspended);
        self::assertStringNotContainsString('ticket-commands.js', $suspended);

        $recalled = $this->session->clickUntilSees(
            'form[action$="/pos/recall"] button[type="submit"]',
            'Current sale',
        );
        self::assertSees('Current sale', $recalled);
        self::assertSees($description, $recalled);
        self::assertSees('register-cart', $recalled);
        self::assertDoesNotMatchRegularExpression('#/pos/suspended#', $this->session->currentPath());
    }

    private function openRegister(): void
    {
        $page = $this->session->open('/' . ProbeMall::SLUG . '/registers');
        if (preg_match('#(/' . ProbeMall::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page, $use) === 1) {
            $active = $this->session->submit($use[1], []);
            self::assertSees('is the active register.', $active);

            return;
        }
        if (str_contains($page, 'Close register') && str_contains($page, 'Active')) {
            return;
        }
        $pattern = '#(/' . ProbeMall::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#';
        if (preg_match($pattern, $page, $match) !== 1) {
            self::fail('the open register action was not on the page');
        }
        $this->session->clickSelector('[data-open-modal="register-open"][data-brand-action="' . $match[1] . '"]');
        $fields = [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
        ];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        $opened = $this->session->submit($match[1], $fields);
        self::assertSees('Close register', $opened);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $this->session->open('/' . ProbeMall::SLUG . '/vendors/new');
        $page = $this->session->submit('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }
}
