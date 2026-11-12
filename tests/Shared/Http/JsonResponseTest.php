<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\JsonResponse;
use PerfectConsign\Shared\Http\ResponseSent;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function testEncodeFailureReturnsErrorObject(): void
    {
        $handle = fopen('php://memory', 'r');
        self::assertNotFalse($handle);
        ob_start();
        try {
            JsonResponse::send(['payload' => $handle], 200, 'lookup response failed');
            ob_end_clean();
            self::fail('expected ResponseSent');
        } catch (ResponseSent) {
            $body = (string) ob_get_clean();
            self::assertSame(500, http_response_code());
            self::assertStringContainsString('"error":"lookup response failed"', $body);
        } finally {
            fclose($handle);
        }
    }
}
