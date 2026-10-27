<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Browser;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Support\ChromeProbe;
use PerfectConsign\Tests\Support\ChromeSession;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('browser')]
final class SurfaceJsClickTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private static ?ChromeProbe $chrome = null;

    private static ?ProbeSurfaceSeed $seed = null;

    private ChromeSession $session;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$seed = ProbeSurfaceSeed::ensure(self::client());
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

    public function testVendorPrintButtonStaysOnStickers(): void
    {
        $seed = $this->seed();
        $this->session->signIn(self::SLUG, $seed->vendorUsername, $seed->vendorPassword);
        $this->session->open('/' . self::SLUG . '/vendor/inventory');
        $printed = $this->session->submitStickerJob(
            '/' . self::SLUG . '/vendor/labels',
            $seed->itemId
        );
        self::assertSees('Print stickers', $printed);
        $after = $this->session->clickSelector('[data-print]', false);
        self::assertSees('Print stickers', $after);
        self::assertStringNotContainsString('This address is not a page in', $after);
    }

    public function testOwnerPreviewPrintBackKeepsAs(): void
    {
        $seed = $this->seed();
        $this->session->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $inventory = VendorPreview::with('/' . self::SLUG . '/vendor/inventory', $seed->vendorId);
        $labels = VendorPreview::with('/' . self::SLUG . '/vendor/labels', $seed->vendorId);
        $this->session->open($inventory);
        $printed = $this->session->submitStickerJob($labels, $seed->itemId);
        self::assertSees('Print stickers', $printed);
        $this->session->clickSelector('[data-print]', false);
        $back = $this->session->clickSelector('a.btn[href*="vendor/inventory"]', true);
        self::assertSees('Your items', $back);
        self::assertStringContainsString('as=', $this->session->currentPath());
        self::assertStringContainsString($seed->vendorId, $this->session->currentPath());
        self::assertStringNotContainsString('This address is not a page in', $back);
    }

    private function seed(): ProbeSurfaceSeed
    {
        $seed = self::$seed;
        if (!$seed instanceof ProbeSurfaceSeed) {
            self::fail('surface seed was not built');
        }

        return $seed;
    }
}
