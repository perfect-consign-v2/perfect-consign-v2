<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\NavClock;
use PHPUnit\Framework\TestCase;

final class NavClockTest extends TestCase
{
    public function testTheFaceUsesTheMallZone(): void
    {
        $clock = new NavClock('America/Los_Angeles');
        $evening = $clock->face(new DateTimeImmutable('2026-11-21 03:38:04', new DateTimeZone('UTC')));
        $morning = $clock->face(new DateTimeImmutable('2026-11-20 17:05:06', new DateTimeZone('UTC')));
        $noon = $clock->face(new DateTimeImmutable('2026-11-20 20:00:00', new DateTimeZone('UTC')));
        $midnight = $clock->face(new DateTimeImmutable('2026-11-20 08:00:00', new DateTimeZone('UTC')));

        self::assertSame('Fri, Nov 20, 2026', $evening['date']);
        self::assertSame('7:38:04 PM', $evening['time']);
        self::assertSame('2026-11-20T19:38:04-08:00', $evening['instant']);
        self::assertSame('9:05:06 AM', $morning['time']);
        self::assertSame('12:00:00 PM', $noon['time']);
        self::assertSame('12:00:00 AM', $midnight['time']);
        self::assertSame('Fri, Nov 20, 2026', $midnight['date']);
    }

    public function testNowRendersAFaceInTheZone(): void
    {
        $face = (new NavClock('UTC'))->face();

        self::assertMatchesRegularExpression('/^[A-Z][a-z]{2}, [A-Z][a-z]{2} \d{1,2}, \d{4}$/', $face['date']);
        self::assertMatchesRegularExpression('/^\d{1,2}:\d{2}:\d{2} [AP]M$/', $face['time']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $face['instant']);
    }

    public function testABlankOrUnknownZoneIsRefused(): void
    {
        try {
            new NavClock('');
            self::fail('a blank zone was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('nav clock needs a time zone', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nav clock time zone is not recognized');
        new NavClock('Not/AZone');
    }

    public function testTheMallHeaderShowsTheClockOnTheLeft(): void
    {
        $zone = 'America/Los_Angeles';
        $face = (new NavClock($zone))->face();
        $html = $this->mall([
            'nav' => true,
            'navZone' => $zone,
        ]);

        $clockAt = strpos($html, 'data-mall-clock');
        $actionsAt = strpos($html, 'topbar-actions');
        self::assertIsInt($clockAt);
        self::assertIsInt($actionsAt);
        self::assertLessThan($actionsAt, $clockAt);
        self::assertStringContainsString('data-time-zone="America/Los_Angeles"', $html);
        self::assertStringContainsString($face['date'], $html);
        self::assertStringContainsString('/assets/js/nav-clock.js', $html);
    }

    public function testAHeaderWithoutAZoneOmitsTheClock(): void
    {
        $html = $this->mall(['nav' => true]);

        self::assertStringNotContainsString('data-mall-clock', $html);
        self::assertStringNotContainsString('nav-clock.js', $html);
    }

    public function testThePlatformHeaderShowsTheServerClock(): void
    {
        $zone = date_default_timezone_get();
        $face = (new NavClock($zone))->face();
        $html = $this->admin(true);

        self::assertStringContainsString('data-time-zone="' . $zone . '"', $html);
        self::assertStringContainsString($face['date'], $html);
        self::assertStringContainsString('/assets/js/nav-clock.js', $html);
    }

    public function testASignedOutPlatformPageOmitsTheClock(): void
    {
        $html = $this->admin(false);

        self::assertStringNotContainsString('data-mall-clock', $html);
        self::assertStringNotContainsString('nav-clock.js', $html);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function mall(array $overrides): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/layout', $overrides + [
            'title' => 'Demo',
            'displayName' => 'Demo Mall',
            'slug' => 'demo',
            'nav' => true,
            'csrf' => 'token',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'path' => '/demo/dashboard',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
            'actorRole' => 'owner',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    private function admin(bool $nav): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Admin/layout', [
            'title' => 'Tenants',
            'csrf' => 'token',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'nav' => $nav,
            'adminEmail' => 'owner@example.test',
            'path' => '/platform-admin-door/tenants',
            'adminPath' => new AdminPath('/platform-admin-door'),
            'displayName' => '',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
