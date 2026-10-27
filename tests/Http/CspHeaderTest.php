<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class CspHeaderTest extends WebTestCase
{
    public function testRootSendsEnforcingCspWithoutUnsafeInline(): void
    {
        $page = self::client()->get('/');
        $csp = $page->header('Content-Security-Policy');
        self::assertNotSame('', $csp);
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("style-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function testAdminLoginSendsEnforcingCsp(): void
    {
        $page = self::client()->get('/admin/login');
        $csp = $page->header('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
    }

    public function testMallDashboardSendsEnforcingCsp(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $page = $client->get('/' . ProbeMall::SLUG . '/dashboard');
        $csp = $page->header('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertSame(200, $page->status);
    }

    public function testPosShellUsesExternalAssetsWithoutInlineScript(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->openRegister($client);
        $page = $client->get('/' . ProbeMall::SLUG . '/pos');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('/assets/js/theme-boot.js', $page->body);
        self::assertStringContainsString('/assets/js/register-ui.js', $page->body);
        self::assertStringContainsString('/assets/js/data-style.js', $page->body);
        self::assertStringContainsString('/assets/js/flash-dialog.js', $page->body);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $page->body);
        self::assertDoesNotMatchRegularExpression('/\bstyle=/', $page->body);
        self::assertStringContainsString("script-src 'self'", $page->header('Content-Security-Policy'));
    }

    public function testPayVendorsPrintUsesDataPrintHook(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $page = $client->get('/' . ProbeMall::SLUG . '/payouts');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-print', $page->body);
        self::assertStringContainsString('/assets/js/print-trigger.js', $page->body);
        self::assertStringNotContainsString('onclick=', $page->body);
    }

    private function openRegister(FormClient $client): void
    {
        $page = $client->get('/' . ProbeMall::SLUG . '/registers');
        if (preg_match('#(/' . ProbeMall::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        self::assertSame(
            1,
            preg_match('#(/' . ProbeMall::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $open),
            'expected an open-register form'
        );
        $counts = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $counts['count_' . $key] = '0';
        }
        $client->post($open[1], [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$counts,
        ]);
    }
}
