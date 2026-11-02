<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Pos\SaleHold;
use PHPUnit\Framework\TestCase;

final class SaleHoldTest extends TestCase
{
    private const JOURNAL = '01900000-0000-7000-8000-0000000000ab';

    public function testLastSaleIgnoresMalformedSessionValues(): void
    {
        $session = new Session([
            'last_sale:demo' => 'not-a-uuid',
            'last_sale:mall' => '01900000-0000-7000-8000-0000000000ab',
        ], false);
        $hold = new SaleHold($session);

        self::assertSame('', $hold->last('demo'));
        self::assertSame('01900000-0000-7000-8000-0000000000ab', $hold->last('mall'));
    }

    public function testLastSaleAcceptsAUuidShape(): void
    {
        $session = new Session(['last_sale:demo' => self::JOURNAL], false);

        self::assertSame(self::JOURNAL, (new SaleHold($session))->last('demo'));
    }
}
