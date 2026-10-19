<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Feature\Vendor\VendorSales;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class VendorSalesTest extends TestCase
{
    public function testEachNamedRangeHasALabel(): void
    {
        $sales = new VendorSales($this->createStub(Statements::class));
        $window = ['from' => '2026-09-01', 'to' => '2026-09-30', 'rent' => '2026-09-01'];

        self::assertSame('Today', $sales->span('2026-10-17', 'today', '', '', null, $window)['label']);
        self::assertSame('2026-10-16', $sales->span('2026-10-17', 'yesterday', '', '', null, $window)['from']);
        self::assertSame('This check', $sales->span('2026-10-17', 'check', '', '', null, $window)['label']);
        $week = $sales->span('2026-10-17', 'week', '', '', null, $window);
        self::assertSame('This week', $week['label']);
        self::assertSame('2026-10-12', $week['from']);
        self::assertSame('2026-10-01', $sales->span('2026-10-17', 'month', '', '', null, $window)['from']);
        $since = $sales->span('2026-10-17', '', '', '', '2026-10-10', $window);
        self::assertSame('2026-10-11', $since['from']);
        self::assertSame('Since your last check', $since['label']);
        $custom = $sales->span('2026-10-17', 'custom', '2026-10-03', '2026-10-04', null, $window);
        self::assertSame('2026-10-03', $custom['from']);
        self::assertSame('Those dates', $custom['label']);
        $named = $sales->span('2026-10-17', 'other', '2026-10-03', '2026-10-04', '2026-10-10', $window);
        self::assertSame('2026-10-11', $named['from']);
        self::assertSame('Since your last check', $named['label']);
    }

    public function testAPreviewTokenMustBeAUuid(): void
    {
        self::assertSame('', VendorPreview::token('not-a-uuid'));
        self::assertSame('01900000-0000-7000-8000-0000000000aa', VendorPreview::token('01900000-0000-7000-8000-0000000000AA'));
        self::assertTrue(VendorPreview::onPortal('/demo/vendor', 'demo'));
        self::assertFalse(VendorPreview::onPortal('/demo/vendors', 'demo'));
        self::assertSame('/demo/vendor', VendorPreview::with('/demo/vendor', ''));
        self::assertStringContainsString('as=', VendorPreview::with('/demo/vendor?q=a', '01900000-0000-7000-8000-0000000000aa'));
    }
}
