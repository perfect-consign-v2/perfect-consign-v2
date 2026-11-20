<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Http;

use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestTrace;
use PHPUnit\Framework\TestCase;

final class RequestJsonTest extends TestCase
{
    public function testAcceptJsonIsAJsonRequest(): void
    {
        $json = new Request('POST', '/pos/scan', [], [], '', '', '', [], new RequestTrace(accept: 'application/json'));
        $page = new Request('POST', '/pos/scan', [], []);
        self::assertTrue($json->wantsJson());
        self::assertFalse($page->wantsJson());
    }
}
