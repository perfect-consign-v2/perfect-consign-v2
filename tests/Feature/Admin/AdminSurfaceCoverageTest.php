<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Feature\Admin\AdminApp;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminPasswordController;
use PerfectConsign\Feature\Admin\AdminPasswordRecovery;
use PerfectConsign\Feature\Admin\AdminResetMail;
use PerfectConsign\Feature\Admin\AdminResetTokens;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSignupController;
use PerfectConsign\Feature\Admin\AdminTenantCreateController;
use PerfectConsign\Feature\Admin\AdminWorkerController;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Signup\SignupRateLimit;
use PerfectConsign\Feature\Signup\StoreSignup;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Jobs\WorkerRuns;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class AdminSurfaceCoverageTest extends TestCase
{
    private const ADMIN_ID = '01900000-0000-7000-8000-0000000000aa';

    private const EMAIL = 'admin@example.test';

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Session $session;

    private bool $passwordSaved = false;

    private string|false $savedPassword = false;

    protected function tearDown(): void
    {
        if (!$this->passwordSaved) {
            return;
        }
        if ($this->savedPassword === false || $this->savedPassword === '') {
            putenv('STALL_WORKER_PASSWORD');
        } else {
            putenv('STALL_WORKER_PASSWORD=' . $this->savedPassword);
        }
    }

    public function testPasswordPagesCoverGuestAndSignedInBranches(): void
    {
        $forgot = $this->shown(function (): void {
            $this->passwords($this->request('GET', '/admin/forgot', []))->forgotForm();
        });
        self::assertStringContainsString('Forgot password', $forgot);

        $this->redirected(function (): void {
            $this->passwords($this->request('POST', '/admin/forgot', [
                'email' => 'not-an-email',
            ]))->forgotSubmit();
        });
        self::assertSame(ResetNotice::SENT, $this->session->get('admin_notice'));

        $this->redirected(function (): void {
            $this->passwords($this->request('POST', '/admin/forgot', [
                'email' => self::EMAIL,
            ], 'not a url'), $this->adminLookup())->forgotSubmit();
        });
        self::assertSame(AdminPasswordRecovery::MAIL_FAILED, $this->session->get('admin_notice'));

        try {
            $this->passwords($this->request('POST', '/admin/forgot', [
                'email' => self::EMAIL,
            ]), $this->throwingPdo())->forgotSubmit();
            self::fail('a database failure was swallowed');
        } catch (RuntimeException $error) {
            self::assertSame('db down', $error->getMessage());
        }

        $open = $this->shown(function (): void {
            $this->passwords($this->request('GET', '/admin/reset/' . self::TOKEN, []), $this->tokenPdo(true))
                ->resetForm(self::TOKEN);
        });
        self::assertStringContainsString('New password', $open);
        self::assertStringNotContainsString('Request a new link', $open);

        $expired = $this->shown(function (): void {
            $this->passwords($this->request('GET', '/admin/reset/short-token-not-used', []), $this->createStub(PDO::class))
                ->resetForm('short-token-not-used-but-long-enough-000000000');
        });
        self::assertStringContainsString('Request a new link', $expired);

        $mismatch = $this->shown(function (): void {
            $this->passwords($this->request('POST', '/admin/reset/' . self::TOKEN, [
                'password' => 'new-pass-1',
                'confirm' => 'new-pass-2',
            ]), $this->redeemPdo('2', 1))->resetSubmit(self::TOKEN);
        });
        self::assertStringContainsString('Those new passwords do not match.', $mismatch);
        self::assertStringContainsString('name="password"', $mismatch);

        $this->redirected(function (): void {
            $this->passwords($this->request('POST', '/admin/reset/' . self::TOKEN, [
                'password' => 'new-pass-1',
                'confirm' => 'new-pass-1',
            ]), $this->redeemPdo('2', 1))->resetSubmit(self::TOKEN);
        });
        self::assertSame('Password saved. Sign in.', $this->session->get('admin_notice'));

        $usedUp = $this->shown(function (): void {
            $this->passwords($this->request('POST', '/admin/reset/short', [
                'password' => 'new-pass-1',
                'confirm' => 'new-pass-1',
            ]))->resetSubmit('short');
        });
        self::assertStringContainsString('That reset link expired. Request a new one.', $usedUp);
        self::assertStringContainsString('Request a new link', $usedUp);

        $form = $this->shown(function (): void {
            $this->passwords($this->request('GET', '/admin/password', []))->changeForm();
        });
        self::assertStringContainsString('Change password', $form);

        $wrong = $this->shown(function (): void {
            $this->passwords($this->request('POST', '/admin/password', [
                'current' => 'wrong-pass',
                'password' => 'new-pass-1',
                'confirm' => 'new-pass-1',
            ]), $this->changePdo('3'))->changeSubmit();
        });
        self::assertStringContainsString('That password is wrong.', $wrong);

        $this->redirected(function (): void {
            $this->passwords($this->request('POST', '/admin/password', [
                'current' => 'old-pass-1',
                'password' => 'new-pass-1',
                'confirm' => 'new-pass-1',
            ]), $this->changePdo('3'))->changeSubmit();
        });
        self::assertSame('Password saved. Sign in with your new password.', $this->session->get('admin_notice'));
    }

    public function testSignupRendersBothSwitchesAndEverySavedSentence(): void
    {
        $on = $this->shown(function (): void {
            $this->signup($this->request('GET', '/admin/signup', []), ['store_signup_enabled' => 'true', 'store_signup_require_email_verification' => 'true'])
                ->show();
        });
        self::assertStringContainsString('checked', $on);

        $off = $this->shown(function (): void {
            $this->signup($this->request('GET', '/admin/signup', []), ['store_signup_enabled' => 'false', 'store_signup_require_email_verification' => 'false'])
                ->show();
        });
        self::assertStringNotContainsString('checked', $off);

        $this->redirected(function (): void {
            $this->signup($this->request('POST', '/admin/signup', [
                'store_signup_enabled' => '1',
                'store_signup_require_email_verification' => '1',
            ]))->save();
        });
        self::assertSame('Public store signup is on. Email confirmation is required.', $this->session->get('admin_notice'));

        $this->redirected(function (): void {
            $this->signup($this->request('POST', '/admin/signup', [
                'store_signup_enabled' => '1',
            ]))->save();
        });
        self::assertSame('Public store signup is on. Email confirmation is off.', $this->session->get('admin_notice'));

        $this->redirected(function (): void {
            $this->signup($this->request('POST', '/admin/signup', []))->save();
        });
        self::assertSame('Public store signup is off.', $this->session->get('admin_notice'));
    }

    public function testTheWorkerScreenPrunesAndClears(): void
    {
        $this->workerPassword('');
        $missing = $this->shown(function (): void {
            $this->worker($this->request('GET', '/admin/worker', []), false, [])->index();
        });
        self::assertStringContainsString('missing', $missing);
        self::assertStringContainsString('never', $missing);
        self::assertStringContainsString('No job-bearing runs recorded yet.', $missing);
        self::assertStringContainsString('STALL_WORKER_PASSWORD', $missing);

        $this->workerPassword('secret');
        $idle = $this->shown(function (): void {
            $this->worker($this->request('GET', '/admin/worker', []), false, [[
                'id' => '1',
                'worker' => 'mail',
                'started_at' => '2026-11-01 00:00',
                'finished_at' => '',
                'jobs_finished' => 3,
                'jobs_failed' => 1,
            ]])->index();
        });
        self::assertStringContainsString('no heartbeat', $idle);
        self::assertStringContainsString('running', $idle);

        $seen = $this->shown(function (): void {
            $this->worker($this->request('GET', '/admin/worker', []), [
                'seen_at' => '2026-11-12 01:00',
                'last_jobs_finished' => 4,
                'last_jobs_failed' => 0,
            ], [[
                'id' => '2',
                'worker' => 'mail',
                'started_at' => '2026-11-12 01:00',
                'finished_at' => '2026-11-12 01:01',
                'jobs_finished' => 4,
                'jobs_failed' => 0,
            ]])->index();
        });
        self::assertStringContainsString('2026-11-12 01:01', $seen);
        self::assertStringContainsString('>set<', $seen);
        self::assertStringNotContainsString('no heartbeat', $seen);

        $days = null;
        $this->redirected(function () use (&$days): void {
            $this->worker($this->request('POST', '/admin/worker/prune', ['days' => '0']), false, [], $days)->prune();
        });
        self::assertSame(30, $days);
        self::assertSame('Pruned 2 worker run row(s) older than 30 day(s).', $this->session->get('admin_notice'));

        $days = null;
        $this->redirected(function () use (&$days): void {
            $this->worker($this->request('POST', '/admin/worker/prune', ['days' => '400']), false, [], $days)->prune();
        });
        self::assertSame(365, $days);
        self::assertSame('Pruned 2 worker run row(s) older than 365 day(s).', $this->session->get('admin_notice'));

        $days = null;
        $this->redirected(function () use (&$days): void {
            $this->worker($this->request('POST', '/admin/worker/prune', ['days' => '14']), false, [], $days)->prune();
        });
        self::assertSame(14, $days);

        $this->redirected(function (): void {
            $this->worker($this->request('POST', '/admin/worker/clear', []), false, [])->clear();
        });
        self::assertSame('Cleared 3 worker run row(s).', $this->session->get('admin_notice'));
    }

    public function testCreateStoreShowsTheFormAndAValidationError(): void
    {
        $form = $this->shown(function (): void {
            $this->stores($this->request('GET', '/admin/tenants/create', []))->form();
        });
        self::assertStringContainsString('Create store', $form);
        self::assertStringNotContainsString('role="alert"', $form);

        $failed = $this->shown(function (): void {
            $this->stores($this->request('POST', '/admin/tenants/create', [
                'store_name' => '',
                'email' => 'owner@example.test',
                'password' => 'password1',
                'password_confirm' => 'password1',
            ]))->create();
        });
        self::assertStringContainsString('Enter a store name.', $failed);
        self::assertStringContainsString('value="owner@example.test"', $failed);

        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Admin/tenant-create', [
            'csrf' => 'tok',
            'error' => '',
            'storeName' => 'A',
            'email' => 'a@b.test',
        ]);
        $emptyError = ob_get_clean();
        self::assertIsString($emptyError);
        self::assertStringNotContainsString('role="alert"', $emptyError);
    }

    private function passwords(Request $request, ?PDO $pdo = null): AdminPasswordController
    {
        $pdo ??= $this->createStub(PDO::class);
        $app = $this->app($request, $this->adminPdo());
        $logger = new NullLogger();
        $tokens = new AdminResetTokens($pdo);
        $recovery = new AdminPasswordRecovery(
            $pdo,
            $request,
            $tokens,
            new PasswordChoice(),
            new AdminResetMail(
                $tokens,
                new PlatformMailbox(
                    new OutboundMail($logger),
                    new GmailSender(new PlatformOperatorNotice($logger)),
                    new PlatformOperatorNotice($logger),
                ),
                $logger,
            ),
            new AdminAudit($pdo, new Request('GET', '/', [], [])),
            new AdminSession($this->session),
            new LoginSession($this->session),
        );

        return new AdminPasswordController($app, $recovery);
    }

    /**
     * @param array<string, string> $settings
     */
    private function signup(Request $request, array $settings = []): AdminSignupController
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use ($settings): PDOStatement {
            $key = '';
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use (&$key): bool {
                $key = (string) ($params[0] ?? '');

                return true;
            });
            $statement->method('fetchColumn')->willReturnCallback(static function () use (&$key, $settings): mixed {
                return $settings[$key] ?? false;
            });
            $statement->method('rowCount')->willReturn(1);

            return $statement;
        });
        $logger = new NullLogger();

        return new AdminSignupController(
            $this->app($request, $this->adminPdo()),
            new PlatformSettings($pdo, $logger),
            new AdminAudit($pdo, new Request('GET', '/', [], [])),
        );
    }

    /**
     * @param array<string, mixed>|false $heartbeat
     * @param list<mixed> $runs
     */
    private function worker(Request $request, array|false $heartbeat, array $runs, ?int &$days = null): AdminWorkerController
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(3);
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($heartbeat): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'worker_heartbeat')) {
                $statement->method('fetch')->willReturn($heartbeat);

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn(0);

            return $statement;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($runs, &$days): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'make_interval')) {
                $statement->method('execute')->willReturnCallback(static function (array $params) use (&$days): bool {
                    $days = $params[0];

                    return true;
                });
                $statement->method('rowCount')->willReturn(2);

                return $statement;
            }
            $left = $runs;
            $statement->method('execute')->willReturn(true);
            $statement->method('fetch')->willReturnCallback(static function () use (&$left): mixed {
                if ($left === []) {
                    return false;
                }

                return array_shift($left);
            });

            return $statement;
        });

        return new AdminWorkerController($this->app($request, $this->adminPdo()), new WorkerRuns($pdo));
    }

    private function stores(Request $request): AdminTenantCreateController
    {
        $logger = new NullLogger();
        $signup = new StoreSignup(
            $this->createStub(PDO::class),
            new PlatformSettings($this->createStub(PDO::class), $logger),
            new SignupRateLimit($this->createStub(PDO::class)),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $logger,
        );

        return new AdminTenantCreateController($this->app($request, $this->adminPdo()), $signup);
    }

    private function app(Request $request, PDO $pdo): AdminApp
    {
        $this->session = new Session([
            AdminSession::ID => self::ADMIN_ID,
            AdminSession::EMAIL => self::EMAIL,
            AdminSession::VERSION => '1',
            'csrf' => 'csrf-token',
            'platform_signed_in_at' => (string) time(),
            'platform_last_seen_at' => (string) time(),
        ], false);

        return new AdminApp(
            $pdo,
            $this->session,
            new View(dirname(__DIR__, 3) . '/src'),
            $request,
            new NullLogger(),
            new AdminSession($this->session),
            new LoginSession($this->session),
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    private function request(string $method, string $path, array $post, string $origin = 'http://127.0.0.1'): Request
    {
        $post['csrf'] = 'csrf-token';

        return new Request($method, $path, [], $post, '', $origin, '203.0.113.50');
    }

    private function adminPdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn([
            'id' => self::ADMIN_ID,
            'email' => self::EMAIL,
            'credentials_version' => '1',
        ]);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function adminLookup(): PDO
    {
        return $this->scripted(function (string $sql): PDOStatement {
            if (str_contains($sql, 'lower(email)')) {
                return $this->statement(false, [
                    'id' => self::ADMIN_ID,
                    'email' => self::EMAIL,
                    'password_hash' => (new PasswordChoice())->hash('old-pass-1'),
                ]);
            }
            if (str_contains($sql, 'client_address')) {
                return $this->statement(0);
            }
            if (str_contains($sql, 'admin_password_reset_requests') && str_contains($sql, 'email')) {
                return $this->statement(0);
            }

            return $this->statement();
        });
    }

    private function throwingPdo(): PDO
    {
        return $this->scripted(static function (): PDOStatement {
            throw new RuntimeException('db down');
        });
    }

    private function tokenPdo(bool $available): PDO
    {
        return $this->scripted(function () use ($available): PDOStatement {
            return $this->statement($available ? 1 : false);
        });
    }

    private function redeemPdo(mixed $version, int $consumed): PDO
    {
        return $this->scripted(function (string $sql) use ($version, $consumed): PDOStatement {
            if (str_contains($sql, 'FOR UPDATE')) {
                return $this->statement(false, [
                    'id' => '01900000-0000-7000-8000-0000000000bb',
                    'account_id' => self::ADMIN_ID,
                ]);
            }
            if (str_contains($sql, 'SELECT 1')) {
                return $this->statement(1);
            }
            if (str_contains($sql, 'FROM platform.admins')) {
                return $this->statement(false, [
                    'id' => self::ADMIN_ID,
                    'email' => self::EMAIL,
                    'password_hash' => (new PasswordChoice())->hash('old-pass-1'),
                ]);
            }
            if (str_contains($sql, 'UPDATE platform.admins')) {
                return $this->statement($version);
            }
            if (str_contains($sql, 'used_at IS NULL')) {
                return $this->statement(false, false, $consumed);
            }

            return $this->statement(false, false, 1);
        });
    }

    private function changePdo(mixed $version): PDO
    {
        return $this->scripted(function (string $sql) use ($version): PDOStatement {
            if (str_contains($sql, 'FROM platform.admins')) {
                return $this->statement(false, [
                    'id' => self::ADMIN_ID,
                    'email' => self::EMAIL,
                    'password_hash' => (new PasswordChoice())->hash('old-pass-1'),
                ]);
            }
            if (str_contains($sql, 'UPDATE platform.admins')) {
                return $this->statement($version);
            }

            return $this->statement(false, false, 1);
        });
    }

    /**
     * @param callable(string): PDOStatement $prepare
     */
    private function scripted(callable $prepare): PDO
    {
        $open = false;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturnCallback($prepare);

        return $pdo;
    }

    private function statement(mixed $column = false, mixed $row = false, int $rowCount = 0): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($column);
        $statement->method('fetch')->willReturn($row);
        $statement->method('rowCount')->willReturn($rowCount);

        return $statement;
    }

    /**
     * @param callable(): void $run
     */
    private function shown(callable $run): string
    {
        ob_start();
        try {
            $run();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
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

    private function workerPassword(string $password): void
    {
        if (!$this->passwordSaved) {
            $current = getenv('STALL_WORKER_PASSWORD');
            $this->savedPassword = is_string($current) ? $current : false;
            $this->passwordSaved = true;
        }
        putenv('STALL_WORKER_PASSWORD=' . $password);
    }
}
