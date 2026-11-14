<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Feature\Admin\AdminApp;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminExpiredFormTest extends TestCase
{
    public function testABadTokenShowsTheExpiredForm(): void
    {
        $session = new Session(['csrf' => 'csrf-token'], false);
        $app = new AdminApp(
            $this->createStub(PDO::class),
            $session,
            new View(dirname(__DIR__, 3) . '/src'),
            new Request('POST', '/admin/login', [], ['csrf' => 'nope'], '', 'https://example.test', '203.0.113.10'),
            new NullLogger(),
            new AdminSession($session),
            new LoginSession($session),
        );
        ob_start();
        try {
            $app->checkCsrf();
            self::fail('a bad token was accepted');
        } catch (ResponseSent $sent) {
            self::assertSame('csrf', $sent->getMessage());
        } finally {
            $html = ob_get_clean();
        }

        self::assertIsString($html);
        self::assertStringContainsString('Form expired', $html);
        self::assertStringContainsString('The form expired. Go back and try again.', $html);
        self::assertStringContainsString('href="/admin/login"', $html);
        self::assertStringContainsString('Back to sign in', $html);
    }
}
