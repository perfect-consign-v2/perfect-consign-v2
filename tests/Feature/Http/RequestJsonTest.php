<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Http;

use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestJsonTest extends TestCase
{
    public function testAcceptJsonIsAJsonRequest(): void
    {
        $json = new Request('POST', '/pos/scan', [], [], '', '', '', [], 'application/json');
        $page = new Request('POST', '/pos/scan', [], []);
        self::assertTrue($json->wantsJson());
        self::assertFalse($page->wantsJson());
    }
}
