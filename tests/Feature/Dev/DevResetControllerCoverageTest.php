<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\CredentialStamp;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\MissingPage;
use PerfectConsign\Bootstrap\OwnerGate;
use PerfectConsign\Bootstrap\PostedRefusal;
use PerfectConsign\Bootstrap\PriorClose;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\StaffSignIn;
use PerfectConsign\Bootstrap\TenantLookup;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Dev\DevResetController;
use PerfectConsign\Feature\Dev\DevTenantReset;
use PerfectConsign\Feature\Dev\MallTenantReset;
use PerfectConsign\Feature\Dev\ShopTenantReset;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DevResetControllerCoverageTest extends TestCase
{
    public function testResetIsHiddenWhenTheOperatorDidNotAllowIt(): void
    {
        $this->withEnv('STALL_ALLOW_TENANT_RESET', null, function (): void {
            $session = $this->bag('mall');
            $controller = $this->controller($session, 'mall', 'RESET');
            $this->sent(function () use ($controller): void {
                $controller->reset('mall');
            }, 'not found');
        });
    }

    public function testResetIsHiddenForACashier(): void
    {
        $this->withEnv('STALL_ALLOW_TENANT_RESET', '1', function (): void {
            $session = $this->bag('mall', 'cashier');
            $controller = $this->controller($session, 'mall', 'RESET');
            $this->sent(function () use ($controller): void {
                $controller->reset('mall');
            }, 'not found');
        });
    }

    public function testResetAsksForTheWordReset(): void
    {
        $this->withEnv('STALL_ALLOW_TENANT_RESET', '1', function (): void {
            $session = $this->bag('mall');
            $controller = $this->controller($session, 'mall', 'no');
            $this->sent(function () use ($controller): void {
                $controller->reset('mall');
            }, 'redirect');
            self::assertSame('Type RESET to confirm.', $session->get('warning'));
        });
    }

    public function testResetStopsBeforeOpeningTheLocalDatabase(): void
    {
        $this->withEnv('STALL_ALLOW_TENANT_RESET', '1', function (): void {
            $this->withEnv('STALL_DB_PORT', '1', function (): void {
                if (getenv('STALL_DB_PORT') !== '1') {
                    self::fail('refusing to reset while the database port is still 5436');
                }
                foreach (['mall', 'shop', 'dev'] as $slug) {
                    $session = $this->bag($slug);
                    $controller = $this->controller($session, $slug, 'RESET');
                    $this->sent(function () use ($controller, $slug): void {
                        $controller->reset($slug);
                    }, 'redirect');
                    self::assertSame('Test data was not reset.', $session->get('warning'));
                }
            });
        });
    }

    public function testAHeldResetFinishesWithoutTouchingTheMalls(): void
    {
        $this->withEnv('STALL_ALLOW_TENANT_RESET', '1', function (): void {
            foreach (['mall', 'shop', 'dev'] as $slug) {
                $session = $this->bag($slug);
                $session->set('register:' . $slug, 'open');
                $mall = new MallTenantReset();
                $shop = new ShopTenantReset();
                $dev = new DevTenantReset();
                $mall->hold();
                $shop->hold();
                $dev->hold();
                $request = new Request('POST', '/' . $slug . '/reset-test-data', [], [
                    'csrf' => 'csrf-token',
                    'confirm' => 'RESET',
                ]);
                $controller = new DevResetController(
                    $this->mall($this->quietPdo(), $session, $request),
                    new Actor($session),
                    $session,
                    new NullLogger(),
                    $dev,
                    $mall,
                    $shop,
                );
                $this->sent(function () use ($controller, $slug): void {
                    $controller->reset($slug);
                }, 'redirect');
                self::assertSame('Test data reset.', $session->get('notice'));
                self::assertNull($session->get('register:' . $slug));
            }
        });
    }

    private function controller(Session $session, string $slug, string $confirm): DevResetController
    {
        $request = new Request('POST', '/' . $slug . '/reset-test-data', [], [
            'csrf' => 'csrf-token',
            'confirm' => $confirm,
        ]);

        return new DevResetController(
            $this->mall($this->quietPdo(), $session, $request),
            new Actor($session),
            $session,
            new NullLogger(),
            new DevTenantReset(),
            new MallTenantReset(),
            new ShopTenantReset(),
        );
    }

    private function mall(PDO $pdo, Session $session, Request $request): MallApp
    {
        $logger = new NullLogger();
        $view = new View(dirname(__DIR__, 3) . '/src');
        $tenants = new TenantLookup($pdo);
        $missing = new MissingPage($session, $logger, $view, $request, $tenants);
        $sessions = new LoginSession($session);
        $owner = new OwnerGate(
            $pdo,
            $session,
            $logger,
            $request,
            $tenants,
            $missing,
            new StaffSignIn($pdo, $logger, $sessions, new SignInFailureRetention($pdo)),
            $sessions,
        );
        $door = new FrontDoor(
            $missing,
            new PriorClose($session, $request),
            $owner,
            new PostedRefusal($logger),
            new Actor($session),
            new CredentialStamp($pdo, $session, new DatabaseRole($pdo)),
        );
        $rows = new Statements($pdo);
        $settings = new MallSettings(
            $rows,
            new CompanyStore($rows, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($rows),
        );

        return new MallApp(
            $pdo,
            $session,
            $view,
            $request,
            new OwnerAccess(),
            $settings,
            new ActiveRegister($pdo, $session, $settings, new MallClock($settings)),
            $tenants,
            $door,
        );
    }

    private function quietPdo(): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);
        $statement->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('query')->willReturn(false);

        return $pdo;
    }

    private function bag(string $slug, string $role = 'owner'): Session
    {
        $now = (string) time();

        return new class ([
            'csrf' => 'csrf-token',
            'actor_role' => $role,
            'owner_slug' => $slug,
            'signed_in_at' => $now,
            'last_seen_at' => $now,
        ]) extends Session {
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

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->bag);
            }

            public function regenerateId(bool $deleteOldSession = true): void
            {
            }
        };
    }

    /**
     * @param callable(): void $run
     */
    private function sent(callable $run, string $message): void
    {
        ob_start();
        try {
            $run();
            self::fail('expected the response to end');
        } catch (ResponseSent $sent) {
            self::assertSame($message, $sent->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @param callable(): void $run
     */
    private function withEnv(string $key, ?string $value, callable $run): void
    {
        $previous = getenv($key);
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
        try {
            $run();
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous);
            }
        }
    }
}
