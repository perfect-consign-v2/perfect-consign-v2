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
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminAppBranchCoverageTest extends TestCase
{
    private const ADMIN_ID = '01900000-0000-7000-8000-0000000000aa';

    public function testAMissingPlatformSessionGoesToSignIn(): void
    {
        $session = $this->session([]);
        $app = $this->app($session, $this->rowPdo(false), $this->request([]));
        $this->redirected(static function () use ($app): void {
            $app->requireAdmin();
        });
        self::assertNull($session->get(AdminSession::ID));
    }

    public function testAMissingAdminRowEndsTheSurface(): void
    {
        $session = $this->signedIn('1');
        $app = $this->app($session, $this->rowPdo(false), $this->request([]));
        $this->redirected(static function () use ($app): void {
            $app->requireAdmin();
        });
        self::assertNull($session->get(AdminSession::ID));
        self::assertIsString($session->get('csrf'));
    }

    public function testAnIntegerCredentialVersionStillMatches(): void
    {
        $session = $this->signedIn('4');
        $app = $this->app($session, $this->rowPdo([
            'id' => self::ADMIN_ID,
            'email' => 'admin@example.test',
            'credentials_version' => 4,
        ]), $this->request([]));
        self::assertSame(
            ['id' => self::ADMIN_ID, 'email' => 'admin@example.test', 'version' => '4'],
            $app->requireAdmin(),
        );
    }

    public function testAChangedCredentialVersionEndsTheSurface(): void
    {
        $session = $this->signedIn('1');
        $app = $this->app($session, $this->rowPdo([
            'id' => self::ADMIN_ID,
            'email' => 'admin@example.test',
            'credentials_version' => '9',
        ]), $this->request([]));
        $this->redirected(static function () use ($app): void {
            $app->requireAdmin();
        });
        self::assertNull($session->get(AdminSession::EMAIL));
    }

    public function testAnUnusableAdminRowEndsTheSurface(): void
    {
        $session = $this->signedIn('1');
        $app = $this->app($session, $this->rowPdo([
            'id' => self::ADMIN_ID,
            'email' => 4,
            'credentials_version' => '1',
        ]), $this->request([]));
        $this->redirected(static function () use ($app): void {
            $app->requireAdmin();
        });
        self::assertNull($session->get(AdminSession::ID));
    }

    public function testAStalePlatformSessionEndsTheSurface(): void
    {
        $session = $this->signedIn('1', '1');
        $app = $this->app($session, $this->rowPdo([
            'id' => self::ADMIN_ID,
            'email' => 'admin@example.test',
            'credentials_version' => '1',
        ]), $this->request([]));
        $this->redirected(static function () use ($app): void {
            $app->requireAdmin();
        });
        self::assertNull($session->get(AdminSession::ID));
    }

    public function testAMatchingCsrfTokenIsAccepted(): void
    {
        $session = $this->session(['csrf' => 'csrf-token']);
        $app = $this->app($session, $this->rowPdo(false), $this->request(['csrf' => 'csrf-token']));
        $app->checkCsrf();
        self::assertSame('csrf-token', $app->token());
    }

    public function testAnExpiredFormIsRenderedAndATokenIsMinted(): void
    {
        $session = $this->session(['admin_notice' => 4, 'admin_warning' => '']);
        $app = $this->app($session, $this->rowPdo(false), $this->request(['csrf' => 'nope']));
        ob_start();
        try {
            $app->checkCsrf();
            self::fail('an expired form continued');
        } catch (ResponseSent $sent) {
            self::assertSame('csrf', $sent->getMessage());
        } finally {
            ob_end_clean();
        }
        self::assertIsString($session->get('csrf'));
        self::assertNotSame('', $session->get('csrf'));
        self::assertNull($session->get('admin_notice'));
    }

    public function testGuestAndSignedInPagesKeepFlashes(): void
    {
        $signedOut = $this->session([
            'admin_notice' => 'Saved',
            'admin_warning' => 'Careful',
        ]);
        $guest = $this->app($signedOut, $this->rowPdo(false), $this->request([]));
        $guestHtml = $this->shown(static function () use ($guest): void {
            $guest->showGuest('Feature/Admin/expired', ['title' => 'Form expired', 'error' => 'Stopped']);
        });
        self::assertStringContainsString('Stopped', $guestHtml);
        self::assertNull($signedOut->get('admin_notice'));
        self::assertNull($signedOut->get('admin_warning'));

        $signedIn = $this->signedIn('1');
        $signedIn->set('csrf', 'already');
        $request = $this->request([]);
        $home = $this->app($signedIn, $this->rowPdo(false), $request);
        $homeHtml = $this->shown(static function () use ($home): void {
            $home->show('Feature/Admin/expired', ['title' => 'Form expired']);
        });
        self::assertStringContainsString('admin@example.test', $homeHtml);
        self::assertSame('already', $home->token());
        self::assertSame($request, $home->request());
        self::assertInstanceOf(AdminSession::class, $home->session());
    }

    public function testRedirectsStoreAndClearFlashes(): void
    {
        $session = $this->session([]);
        $app = $this->app($session, $this->rowPdo(false), $this->request([]));
        $this->redirected(static function () use ($app): void {
            $app->redirect('/admin/tenants', 'Deleted', 'Look again');
        });
        self::assertSame('Deleted', $session->get('admin_notice'));
        self::assertSame('Look again', $session->get('admin_warning'));

        $this->redirected(static function () use ($app): void {
            $app->redirect('/admin/login', '', null);
        });
        self::assertNull($session->get('admin_notice'));
        self::assertNull($session->get('admin_warning'));
    }

    public function testEndingTheSurfaceClearsBothSessions(): void
    {
        $session = $this->signedIn('1');
        $session->set('owner_slug', 'mall');
        $app = $this->app($session, $this->rowPdo(false), $this->request([]));
        $app->endSurface();
        self::assertNull($session->get(AdminSession::ID));
        self::assertNull($session->get('owner_slug'));
        self::assertIsString($session->get('csrf'));
    }

    /**
     * @param callable(): void $run
     */
    private function redirected(callable $run): void
    {
        ob_start();
        try {
            $run();
            self::fail('expected a redirect');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @param callable(): void $run
     */
    private function shown(callable $run): string
    {
        ob_start();
        try {
            $run();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function request(array $post): Request
    {
        return new Request('POST', '/admin/tenants', [], $post, '', 'http://127.0.0.1', '127.0.0.1');
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function rowPdo(array|false $row): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn($row);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function signedIn(string $version, ?string $signedIn = null): Session
    {
        $now = (string) time();

        return $this->session([
            AdminSession::ID => self::ADMIN_ID,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => $version,
            'platform_signed_in_at' => $signedIn ?? $now,
            'platform_last_seen_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function session(array $bag): Session
    {
        return new class ($bag) extends Session {
            /** @param array<string, mixed> $bag */
            public function __construct(private array $bag)
            {
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->bag[$key]);
            }

            public function regenerateId(bool $deleteOldSession = true): void
            {
            }
        };
    }

    private function app(Session $session, PDO $pdo, Request $request): AdminApp
    {
        return new AdminApp(
            $pdo,
            $session,
            new View(dirname(__DIR__, 3) . '/src'),
            $request,
            new NullLogger(),
            new AdminSession($session),
            new LoginSession($session),
        );
    }
}
