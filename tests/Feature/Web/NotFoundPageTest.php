<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class NotFoundPageTest extends TestCase
{
    public function testAnUnknownMallNamesPerfectConsign(): void
    {
        $html = $this->page('', '', '', '');

        self::assertStringContainsString('This address is not in Perfect Consign.', $html);
        self::assertStringContainsString('class="login-wrap"', $html);
        self::assertSame(5, substr_count($html, '</div>'));
        self::assertStringNotContainsString('class="row gap-3"', $html);
    }

    public function testAKnownMallLinksHome(): void
    {
        $html = $this->page('Demo Mall', 'demo', '', '');

        self::assertStringContainsString('This address is not a page in Demo Mall.', $html);
        self::assertStringContainsString('href="/demo/dashboard"', $html);
        self::assertStringNotContainsString('class="login-wrap"', $html);
    }

    public function testASignedOutMallLinksToSignIn(): void
    {
        $html = $this->page('', '', 'demo', '');

        self::assertStringContainsString('href="/"', $html);
        self::assertStringNotContainsString('href="/demo/dashboard"', $html);
    }

    public function testABackLinkStandsAlone(): void
    {
        $html = $this->page('', '', '', '/demo/pos');

        self::assertStringContainsString('href="/demo/pos"', $html);
        self::assertStringNotContainsString('btn-primary', $html);
    }

    private function page(string $displayName, string $homeSlug, string $signInSlug, string $back): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/not-found', [
            'displayName' => $displayName,
            'homeSlug' => $homeSlug,
            'signInSlug' => $signInSlug,
            'back' => $back,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
