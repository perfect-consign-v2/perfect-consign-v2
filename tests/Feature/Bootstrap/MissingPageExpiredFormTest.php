<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectConsign\Bootstrap\MissingPage;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class MissingPageExpiredFormTest extends TestCase
{
    public function testExpiredFormRendersTheLayoutAndStops(): void
    {
        $page = (new ReflectionClass(MissingPage::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(MissingPage::class, 'view'))->setValue(
            $page,
            new View(dirname(__DIR__, 3) . '/src')
        );
        (new ReflectionProperty(MissingPage::class, 'request'))->setValue(
            $page,
            new Request('POST', '/demo/logout', [], [])
        );

        ob_start();
        try {
            $page->expiredForm('csrf-token', 'demo');
            self::fail('expired form must stop the response');
        } catch (ResponseSent $sent) {
            $body = ob_get_clean();
            self::assertSame('csrf', $sent->getMessage());
            self::assertIsString($body);
            self::assertSame(400, http_response_code());
            self::assertStringContainsString('The form expired. Go back and try again.', $body);
            self::assertStringContainsString('Form expired', $body);
        }
    }
}
