<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectApp\Container\Container;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Feature\Admin\AdminApp;
use PerfectConsign\Feature\Admin\AdminAppLogController;
use PerfectConsign\Feature\Admin\AdminAuthenticatorController;
use PerfectConsign\Feature\Admin\AdminEnterAs;
use PerfectConsign\Feature\Admin\AdminMfaController;
use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Feature\Admin\AdminRouter;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSessionController;
use PerfectConsign\Feature\Admin\AdminTenantController;
use PerfectConsign\Feature\Admin\AdminTenantList;
use PerfectConsign\Feature\Admin\AdminTenantWipe;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaChallenge;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaEnrollment;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaFailures;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaKeyFile;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaLogin;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaSeal;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaSwitch;
use PerfectConsign\Feature\Admin\Mfa\Base32;
use PerfectConsign\Feature\Admin\Mfa\SetupCode;
use PerfectConsign\Feature\Admin\Mfa\SetupCodeMarkup;
use PerfectConsign\Feature\Admin\Mfa\Totp;
use PerfectConsign\Feature\Admin\TenantDeletePolicy;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\QuietAudit;
use PerfectConsign\Tests\Support\ScriptPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class MissedAdminLinesTest extends TestCase
{
    use CapturesPage;

    private const ADMIN = '01900000-0000-7000-8000-0000000000b1';

    private const EMAIL = 'ada@example.test';

    public function testAdminPathsRoutersAndSealsRefuseTheBadShapes(): void
    {
        try {
            new AdminPath('/admin');
            self::fail('the public admin path was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('STALL_ADMIN_PATH is not a private admin path', $error->getMessage());
        }
        $path = new AdminPath(AdminDoor::prefix());
        try {
            $path->url('login');
            self::fail('a relative admin link was built');
        } catch (RuntimeException $error) {
            self::assertSame('admin link is not a path', $error->getMessage());
        }
        $saved = getenv('STALL_ADMIN_PATH');
        putenv('STALL_ADMIN_PATH');
        try {
            AdminPath::fromEnv();
            self::fail('an empty admin path was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('STALL_ADMIN_PATH is not set', $error->getMessage());
        } finally {
            if ($saved === false) {
                putenv('STALL_ADMIN_PATH');
            } else {
                putenv('STALL_ADMIN_PATH=' . $saved);
            }
        }

        $router = new AdminRouter($path);
        $router->register(dirname(__DIR__, 3) . '/src/Feature');
        $empty = new class extends Container {
            public function get(string $id): mixed
            {
                return null;
            }
        };
        try {
            $router->dispatch($path->url('/login'), 'GET', $empty);
            self::fail('a missing admin controller ran');
        } catch (RuntimeException $error) {
            self::assertSame('admin controller was not built', $error->getMessage());
        }
        $foreign = new class extends Container {
            public function get(string $id): mixed
            {
                return new \stdClass();
            }
        };
        try {
            $router->dispatch($path->url('/login'), 'GET', $foreign);
            self::fail('a foreign admin action ran');
        } catch (RuntimeException $error) {
            self::assertSame('admin controller action is missing', $error->getMessage());
        }

        self::assertNotSame('', Base32::encode('a'));
        $markup = new SetupCodeMarkup();
        try {
            $markup->image('<html></html>', '');
            self::fail('an html document was accepted as a code');
        } catch (RuntimeException $error) {
            self::assertSame('the authenticator image could not be drawn', $error->getMessage());
        }
        try {
            $markup->image(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1" preserveAspectRatio="xMidYMid"></svg>',
                '',
            );
            self::fail('an empty drawing was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('the authenticator image could not be drawn', $error->getMessage());
        }

        $this->refuseSeals();
        $this->refuseKeyFile();
    }

    public function testAuthenticatorSecretsAndChallengesRefuseTheBrokenCases(): void
    {
        $session = new BagSession([
            AdminMfaPending::KIND => 'enroll',
            AdminMfaPending::AT => '1',
            AdminMfaPending::ID => self::ADMIN,
            AdminMfaPending::EMAIL => self::EMAIL,
        ]);
        $pending = new AdminMfaPending($session, new AdminSession($session));
        self::assertNull($pending->enrollment(time()));
        self::assertSame('That sign-in expired. Sign in again.', $session->get('admin_warning'));

        $store = new AdminMfaStore(new ScriptPdo(), $this->audit(new ScriptPdo()));
        $login = new AdminMfaLogin($store, new AdminSession(new BagSession()), $this->audit(new ScriptPdo()), $this->failures(new ScriptPdo()));
        try {
            $login->open(self::ADMIN, self::EMAIL);
            self::fail('a missing admin version opened a session');
        } catch (RuntimeException $error) {
            self::assertSame('the platform admin session is missing', $error->getMessage());
        }

        $enrollment = $this->enrollment(new ScriptPdo());
        $attempt = $enrollment->confirm('000000', time());
        self::assertFalse($attempt->opened());
        self::assertSame('That sign-in expired. Sign in again.', $attempt->message());

        $raw = AdminDoor::SECRET;
        $secret = Base32::encode($raw);
        $now = time();
        $corrupt = $this->enrollment($this->books(), $this->pendingBag('!!!!'));
        self::assertSame('That sign-in expired. Sign in again.', $corrupt->confirm('000000', $now)->message());
        $wrong = $this->enrollment($this->books(), $this->pendingBag($secret));
        $missed = $wrong->confirm('000000', $now);
        self::assertSame('That code is wrong.', $missed->message());
        $bare = $this->enrollment($this->books(), $this->pendingBag($secret));
        self::assertSame('That sign-in expired. Sign in again.', $bare->accept($now)->message());
        $taken = $this->enrollment(new ScriptPdo(), $this->pendingBag($secret));
        $code = Totp::codeForStep($raw, Totp::stepForTime($now));
        self::assertSame('That authenticator was already saved. Sign in again.', $taken->confirm($code, $now)->message());

        $challenge = $this->challenge(new ScriptPdo());
        self::assertSame('That sign-in expired. Sign in again.', $challenge->submit('123456', '', $now)->message());
        $sealed = $this->challenge($this->secretBook('nope', 0), $this->challengeBag());
        self::assertSame('The authenticator could not be read.', $sealed->submit($code, '', $now)->message());
        $missing = $this->challenge(new ScriptPdo(), $this->challengeBag());
        self::assertSame('That sign-in expired. Sign in again.', $missing->submit($code, '', $now)->message());
        $replayBook = new ScriptPdo();
        $replayBook->pushPrepare('mfa_last_used_step', [[
            'mfa_secret' => AdminMfaSeal::seal($raw),
            'mfa_last_used_step' => '0',
        ]]);
        $replayBook->pushPrepare('mfa_last_used_step', []);
        $replay = $this->challenge($replayBook, $this->challengeBag());
        self::assertSame('That code was already used.', $replay->submit($code, '', $now)->message());

        $empty = new ScriptPdo();
        self::assertFalse((new AdminMfaStore($empty, $this->audit($empty)))->enroll(self::ADMIN, self::EMAIL, 'v1:x', 1, ['hash']));
        $broken = new ScriptPdo();
        $broken->pushPrepare('RETURNING id', [['id' => self::ADMIN]]);
        $broken->oncePrepareThrows('admin_mfa_recovery_codes');
        try {
            (new AdminMfaStore($broken, $this->audit($broken)))->enroll(self::ADMIN, self::EMAIL, 'v1:x', 1, ['hash']);
            self::fail('a recovery-code insert was stored');
        } catch (RuntimeException $error) {
            self::assertSame('prepared statement failed', $error->getMessage());
        }
    }

    public function testSwitchingTheAuthenticatorRollsBackABrokenWrite(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $thrown = null;
        try {
            $this->switcher($this->refusalPdo($hash))->turnOn(self::ADMIN, self::EMAIL, 'secret');
        } catch (RuntimeException $error) {
            $thrown = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('platform setting was not saved', $thrown->getMessage());

        $off = $this->switchBook($hash);
        $off->onPrepare('mfa_secret', []);
        try {
            $this->switcher($off)->turnOff(self::ADMIN, self::EMAIL, 'secret', '123456', time());
            self::fail('a missing authenticator was turned off');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Set up an authenticator before turning it off.', $error->getMessage());
        }

        $raw = AdminDoor::SECRET;
        $now = time();
        $code = Totp::codeForStep($raw, Totp::stepForTime($now));
        $unreadable = $this->switchBook($hash);
        $unreadable->onPrepare('mfa_last_used_step', [['mfa_secret' => 'nope', 'mfa_last_used_step' => '0']]);
        $unreadable->onPrepare('mfa_secret', [['mfa_secret' => 'present']]);
        try {
            $this->switcher($unreadable)->turnOff(self::ADMIN, self::EMAIL, 'secret', $code, $now);
            self::fail('a broken seal was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('The authenticator could not be read.', $error->getMessage());
        }

        $used = $this->switchBook($hash);
        $used->pushPrepare('mfa_last_used_step', [['mfa_secret' => AdminMfaSeal::seal($raw), 'mfa_last_used_step' => '0']]);
        $used->pushPrepare('mfa_last_used_step', []);
        $used->onPrepare('mfa_secret', [['mfa_secret' => 'present']]);
        try {
            $this->switcher($used)->turnOff(self::ADMIN, self::EMAIL, 'secret', $code, $now);
            self::fail('a used code turned the authenticator off');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That code was already used.', $error->getMessage());
        }

        $unsaved = null;
        try {
            $this->switcher($this->refusalPdo($hash, true, AdminMfaSeal::seal($raw), true))->turnOff(
                self::ADMIN,
                self::EMAIL,
                'secret',
                $code,
                $now,
            );
        } catch (RuntimeException $error) {
            $unsaved = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $unsaved);
        self::assertSame('platform setting was not saved', $unsaved->getMessage());
    }

    public function testAdminScreensRefuseAnExpiredCodeAndAProductionDelete(): void
    {
        $secret = Base32::encode(AdminDoor::SECRET);
        $expired = $this->page($this->enrollBag($secret, false), 'GET', []);
        $page = $this->capture(function () use ($expired): void {
            $expired['controller']->enrollForm();
        });
        self::assertSame('That sign-in expired. Sign in again.', $expired['session']->get('admin_notice'));
        self::assertSame('', $page);

        $posted = $this->page($this->enrollBag($secret, false), 'POST', ['csrf' => 'csrf-token', 'code' => '000000']);
        $this->capture(function () use ($posted): void {
            $posted['controller']->enrollSubmit();
        });
        self::assertSame('That sign-in expired. Sign in again.', $posted['session']->get('admin_notice'));

        $locked = $this->page($this->enrollBag($secret, true), 'POST', ['csrf' => 'csrf-token', 'code' => '000000'], 5);
        $lockedPage = $this->capture(function () use ($locked): void {
            $locked['controller']->enrollSubmit();
        });
        self::assertStringContainsString('Too many sign-in attempts', $lockedPage);

        $wrong = $this->page($this->enrollBag($secret, true), 'POST', ['csrf' => 'csrf-token', 'code' => '000000']);
        $wrongPage = $this->capture(function () use ($wrong): void {
            $wrong['controller']->enrollSubmit();
        });
        self::assertStringContainsString('That code is wrong.', $wrongPage);

        $codes = $this->page($this->codeBag(true), 'GET', [], 0, true);
        $this->capture(function () use ($codes): void {
            $codes['controller']->codesForm();
        });
        self::assertSame('That sign-in expired. Sign in again.', $codes['session']->get('admin_notice'));

        $blankCodes = $this->page($this->codeBag(true), 'POST', ['csrf' => 'csrf-token'], 0, true);
        $this->capture(function () use ($blankCodes): void {
            $blankCodes['controller']->codesSubmit();
        });
        self::assertSame('That sign-in expired. Sign in again.', $blankCodes['session']->get('admin_notice'));

        $shown = $this->page($this->codeBag(true), 'POST', ['csrf' => 'csrf-token']);
        $shownPage = $this->capture(function () use ($shown): void {
            $shown['controller']->codesSubmit();
        });
        self::assertStringContainsString('That sign-in expired. Sign in again.', $shownPage);

        $challenge = $this->page($this->challengeSession(), 'POST', ['csrf' => 'csrf-token', 'code' => '123456'], 5);
        $challengePage = $this->capture(function () use ($challenge): void {
            $challenge['controller']->challengeSubmit();
        });
        self::assertStringContainsString('Too many sign-in attempts', $challengePage);

        $waiting = $this->adminApp(new BagSession($this->challengeSession()), new Request('GET', AdminDoor::prefix() . '/login', [], []));
        $sessionController = (new ReflectionClass(AdminSessionController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AdminSessionController::class, 'app'))->setValue($sessionController, $waiting);
        $this->capture(static function () use ($sessionController): void {
            $sessionController->form();
        });

        $this->authenticator('true', ['csrf' => 'csrf-token', 'admin_mfa_enabled' => '1'], 'Authenticator is already on.');
        $this->authenticator('false', ['csrf' => 'csrf-token'], 'Authenticator is already off.');
        $this->openAuthenticator();

        $logApp = $this->adminApp($this->signedIn(), new Request('POST', AdminDoor::prefix() . '/log/lift', [], [
            'csrf' => 'csrf-token',
            'address' => '***',
        ]), $this->adminRow());
        $log = (new ReflectionClass(AdminAppLogController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AdminAppLogController::class, 'app'))->setValue($log, $logApp);
        $this->capture(static function () use ($log): void {
            $log->lift();
        });

        $enter = (new ReflectionClass(AdminEnterAs::class))->newInstanceWithoutConstructor();
        try {
            (new \ReflectionMethod(AdminEnterAs::class, 'searchPath'))->invoke($enter, 'nope');
            self::fail('a public schema was searched');
        } catch (RuntimeException $error) {
            self::assertSame('the mall search path could not be set', $error->getMessage());
        }

        $this->productionDelete();
    }

    private function refuseSeals(): void
    {
        $saved = getenv('STALL_ADMIN_MFA_KEY');
        $long = 'v1:' . sodium_bin2base64(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1), SODIUM_BASE64_VARIANT_ORIGINAL);
        $cases = [
            ['v1:nope', 'the authenticator secret is not sealed', null],
            ['v1:!!!', 'the authenticator secret is not sealed', null],
            ['v1:' . sodium_bin2base64('a', SODIUM_BASE64_VARIANT_ORIGINAL), 'the authenticator secret is not sealed', null],
            [$long, 'STALL_ADMIN_MFA_KEY is required', ''],
            [$long, 'STALL_ADMIN_MFA_KEY is not a secret-box key', '!!!'],
            [$long, 'STALL_ADMIN_MFA_KEY is not a secret-box key', sodium_bin2base64('abcd', SODIUM_BASE64_VARIANT_ORIGINAL)],
        ];
        try {
            foreach ($cases as [$stored, $message, $key]) {
                if ($key === null) {
                    if ($saved === false) {
                        putenv('STALL_ADMIN_MFA_KEY');
                    } else {
                        putenv('STALL_ADMIN_MFA_KEY=' . $saved);
                    }
                } elseif ($key === '') {
                    putenv('STALL_ADMIN_MFA_KEY');
                } else {
                    putenv('STALL_ADMIN_MFA_KEY=' . $key);
                }
                try {
                    AdminMfaSeal::open($stored);
                    self::fail('a broken seal opened');
                } catch (RuntimeException $error) {
                    self::assertSame($message, $error->getMessage());
                }
            }
            try {
                AdminMfaSeal::open('sealed');
                self::fail('an unversioned seal opened');
            } catch (RuntimeException $error) {
                self::assertSame('the authenticator secret is not sealed', $error->getMessage());
            }
        } finally {
            if ($saved === false) {
                putenv('STALL_ADMIN_MFA_KEY');
            } else {
                putenv('STALL_ADMIN_MFA_KEY=' . $saved);
            }
        }
    }

    private function refuseKeyFile(): void
    {
        try {
            AdminMfaKeyFile::present(sys_get_temp_dir(), -1);
            self::fail('a negative enrollment count was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('the enrolled admin count is invalid', $error->getMessage());
        }
        $short = tempnam(sys_get_temp_dir(), 'mfa');
        self::assertIsString($short);
        try {
            $written = file_put_contents($short, "STALL_ADMIN_MFA_KEY=YQ==\n");
            self::assertNotFalse($written);
            try {
                AdminMfaKeyFile::present($short, 0);
                self::fail('a short key was kept');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_ADMIN_MFA_KEY is not a secret-box key', $error->getMessage());
            }
        } finally {
            if (is_file($short)) {
                unlink($short);
            }
        }

        EnvFileProbe::register();
        $this->silence(static function (): void {
            try {
                AdminMfaKeyFile::present('envprobe://deny-read/key', 0);
                self::fail('an unreadable env file was updated');
            } catch (RuntimeException $error) {
                self::assertSame('the environment file could not be read', $error->getMessage());
            }
            try {
                AdminMfaKeyFile::present('envprobe://deny-write/key', 0);
                self::fail('an unwritable env file was updated');
            } catch (RuntimeException $error) {
                self::assertSame('the environment file could not be updated', $error->getMessage());
            }
        });
    }

    private function silence(callable $work): void
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $work();
        } finally {
            restore_error_handler();
        }
    }

    private function productionDelete(): void
    {
        $schema = 'tenant_' . str_repeat('ab', 16);
        $list = new ScriptPdo();
        $list->onPrepare('platform.tenants', [[
            'id' => MallDoor::TENANT,
            'slug' => MallDoor::SLUG,
            'schema_name' => $schema,
            'display_name' => 'Gap',
        ]]);
        $request = new Request('POST', AdminDoor::prefix() . '/tenants/' . MallDoor::SLUG . '/delete', [], [
            'csrf' => 'csrf-token',
            'confirm_slug' => MallDoor::SLUG,
            'second_email' => '',
            'second_password' => '',
        ]);
        $app = $this->adminApp($this->signedIn(), $request, $this->adminRow());
        $wipePdo = $this->createStub(PDO::class);
        $wipePdo->method('prepare')->willReturnCallback(function (): \PDOStatement {
            $statement = $this->createStub(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('rowCount')->willReturn(1);
            $statement->method('fetchColumn')->willReturn(false);

            return $statement;
        });
        $wipe = new AdminTenantWipe($wipePdo, QuietAudit::admin($wipePdo, $request), new BackupStore($wipePdo), $request);
        $controller = new AdminTenantController(
            $app,
            new AdminTenantList($list, new DatabaseRole($list)),
            (new ReflectionClass(AdminEnterAs::class))->newInstanceWithoutConstructor(),
            $wipe,
            new TenantDeletePolicy(),
        );
        $saved = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $page = $this->capture(static function () use ($controller): void {
                $controller->deleteSubmit(MallDoor::SLUG);
            });
        } finally {
            if ($saved === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $saved);
            }
        }
        self::assertStringContainsString('A second platform admin must sign this deletion.', $page);
    }

    private function openAuthenticator(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
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
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($hash): \PDOStatement {
            $statement = $this->createStub(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('rowCount')->willReturn(1);
            $statement->method('fetch')->willReturnCallback(static function () use ($sql): mixed {
                if (str_contains($sql, 'credentials_version')) {
                    return [
                        'id' => self::ADMIN,
                        'email' => self::EMAIL,
                        'credentials_version' => '1',
                    ];
                }

                return false;
            });
            $statement->method('fetchColumn')->willReturnCallback(static function () use ($sql, $hash): mixed {
                if (str_contains($sql, 'password_hash')) {
                    return $hash;
                }
                if (str_contains($sql, 'platform.settings')) {
                    return 'false';
                }

                return 0;
            });

            return $statement;
        });
        $session = $this->signedIn();
        $app = $this->adminApp($session, new Request('POST', AdminDoor::prefix() . '/authenticator', [], [
            'csrf' => 'csrf-token',
            'admin_mfa_enabled' => '1',
            'current' => 'secret',
        ]), $pdo);
        $pending = new AdminMfaPending($session, new AdminSession($session));
        $controller = new AdminAuthenticatorController(
            $app,
            new PlatformSettings($pdo, new NullLogger()),
            new AdminMfaStore($pdo, $this->audit($pdo)),
            $this->switcher($pdo),
            $pending,
        );
        $this->capture(static function () use ($controller): void {
            $controller->save();
        });
        self::assertSame('/mfa/enroll', $pending->target(time()));
    }

    private function authenticator(string $enabled, array $post, ?string $notice): void
    {
        $pdo = $this->adminRow();
        $pdo->onPrepare('password_hash', [['password_hash' => password_hash('secret', PASSWORD_DEFAULT)]]);
        $pdo->onPrepare('platform.settings', [['value' => $enabled]]);
        $pdo->onPrepare('mfa_secret', []);
        $session = $this->signedIn();
        $app = $this->adminApp($session, new Request('POST', AdminDoor::prefix() . '/authenticator', [], $post), $pdo);
        $pending = new AdminMfaPending($session, new AdminSession($session));
        $store = new AdminMfaStore($pdo, $this->audit($pdo));
        $controller = new AdminAuthenticatorController(
            $app,
            new PlatformSettings($pdo, new NullLogger()),
            $store,
            $this->switcher($pdo),
            $pending,
        );
        $this->capture(static function () use ($controller): void {
            $controller->save();
        });
        if ($notice !== null) {
            self::assertSame($notice, $session->get('admin_notice'));
        } else {
            self::assertSame('/mfa/enroll', $pending->target(time()));
        }
    }

    /**
     * @param array<string, mixed> $bag
     * @param array<string, string> $post
     * @return array{controller: AdminMfaController, session: Session}
     */
    private function page(array $bag, string $method, array $post, int $failures = 0, bool $expireCodes = false): array
    {
        $session = $expireCodes ? new ExpiringCodeSession($bag) : new BagSession($bag);
        $pdo = new ScriptPdo();
        if ($failures > 0) {
            $pdo->onPrepare('admin_mfa_failures', [['c' => (string) $failures]]);
        }
        $request = new Request($method, AdminDoor::prefix() . '/mfa', [], $post);
        $app = $this->adminApp($session, $request, $pdo);
        $pending = new AdminMfaPending($session, new AdminSession($session));
        $audit = $this->audit($pdo);
        $store = new AdminMfaStore($pdo, $audit);
        $failureBook = $this->failures($pdo);
        $login = new AdminMfaLogin($store, new AdminSession($session), $audit, $failureBook);
        $logger = new NullLogger();

        return [
            'controller' => new AdminMfaController(
                $app,
                $pending,
                new AdminMfaEnrollment($pending, $store, $failureBook, $login, $logger),
                new AdminMfaChallenge($pending, $store, $failureBook, $login, $logger),
                new SetupCode($logger),
            ),
            'session' => $session,
        ];
    }

    private function adminApp(Session $session, Request $request, ?PDO $pdo = null): AdminApp
    {
        return new AdminApp(
            $pdo ?? new ScriptPdo(),
            $session,
            new View(dirname(__DIR__, 3) . '/src'),
            $request,
            new NullLogger(),
            new AdminSession($session),
            new LoginSession($session),
            new AdminPath(AdminDoor::prefix()),
        );
    }

    private function signedIn(): BagSession
    {
        $now = (string) time();

        return new BagSession([
            'csrf' => 'csrf-token',
            AdminSession::ID => self::ADMIN,
            AdminSession::EMAIL => self::EMAIL,
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => $now,
            'platform_last_seen_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollBag(string $secret, bool $withSecret): array
    {
        $bag = [
            'csrf' => 'csrf-token',
            AdminMfaPending::KIND => 'enroll',
            AdminMfaPending::AT => (string) time(),
            AdminMfaPending::ID => self::ADMIN,
            AdminMfaPending::EMAIL => self::EMAIL,
        ];
        if ($withSecret) {
            $bag[AdminMfaPending::SECRET] = $secret;
        }

        return $bag;
    }

    /**
     * @return array<string, mixed>
     */
    private function codeBag(bool $listed): array
    {
        return [
            'csrf' => 'csrf-token',
            AdminMfaPending::KIND => 'enroll',
            AdminMfaPending::AT => (string) time(),
            AdminMfaPending::ID => self::ADMIN,
            AdminMfaPending::EMAIL => self::EMAIL,
            AdminMfaPending::CODES => $listed ? ['abcd-efgh'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeSession(): array
    {
        return [
            'csrf' => 'csrf-token',
            AdminMfaPending::KIND => 'challenge',
            AdminMfaPending::AT => (string) time(),
            AdminMfaPending::ID => self::ADMIN,
            AdminMfaPending::EMAIL => self::EMAIL,
        ];
    }

    private function adminRow(): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('credentials_version', [[
            'id' => self::ADMIN,
            'email' => self::EMAIL,
            'credentials_version' => '1',
        ]]);

        return $pdo;
    }

    private function switchBook(string $hash): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('password_hash', [['password_hash' => $hash]]);

        return $pdo;
    }

    private function switcher(PDO $pdo): AdminMfaSwitch
    {
        $request = new Request('POST', '/', [], [], '', '', '127.0.0.1');

        return new AdminMfaSwitch(
            $pdo,
            new PlatformSettings($pdo, new NullLogger()),
            new AdminMfaStore($pdo, $this->audit($pdo)),
            $this->failures($pdo),
            $this->audit($pdo),
            \PerfectConsign\Tests\Support\SignInTestKit::gate(\PerfectConsign\Tests\Support\SignInTestKit::openPdo(), $request),
            new NullLogger(),
        );
    }

    private function books(): ScriptPdo
    {
        return new ScriptPdo();
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingBag(string $secret): array
    {
        return $this->enrollBag($secret, true);
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function enrollment(ScriptPdo $pdo, array $bag = []): AdminMfaEnrollment
    {
        $session = new BagSession($bag);
        $pending = new AdminMfaPending($session, new AdminSession($session));

        return new AdminMfaEnrollment($pending, new AdminMfaStore($pdo, $this->audit($pdo)), $this->failures($pdo), new AdminMfaLogin(
            new AdminMfaStore($pdo, $this->audit($pdo)),
            new AdminSession($session),
            $this->audit($pdo),
            $this->failures($pdo),
        ), new NullLogger());
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function challenge(ScriptPdo $pdo, array $bag = []): AdminMfaChallenge
    {
        $session = new BagSession($bag);
        $pending = new AdminMfaPending($session, new AdminSession($session));
        $store = new AdminMfaStore($pdo, $this->audit($pdo));

        return new AdminMfaChallenge(
            $pending,
            $store,
            $this->failures($pdo),
            new AdminMfaLogin($store, new AdminSession($session), $this->audit($pdo), $this->failures($pdo)),
            new NullLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeBag(): array
    {
        return $this->challengeSession();
    }

    private function secretBook(string $secret, int $step): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('mfa_last_used_step', [[
            'mfa_secret' => $secret,
            'mfa_last_used_step' => (string) $step,
        ]]);

        return $pdo;
    }

    private function refusalPdo(string $hash, bool $hasSecret = false, ?string $sealed = null, bool $advance = false): PDO&\PHPUnit\Framework\MockObject\Stub
    {
        $open = false;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($hash, $hasSecret, $sealed, $advance): \PDOStatement {
            $statement = $this->createStub(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('rowCount')->willReturn(0);
            $statement->method('fetch')->willReturnCallback(static function () use ($sql, $sealed): mixed {
                if ($sealed !== null && str_contains($sql, 'mfa_secret') && str_contains($sql, 'mfa_last_used_step')) {
                    return ['mfa_secret' => $sealed, 'mfa_last_used_step' => 0];
                }

                return false;
            });
            $statement->method('fetchColumn')->willReturnCallback(static function () use ($sql, $hash, $hasSecret, $advance): mixed {
                if (str_contains($sql, 'password_hash')) {
                    return $hash;
                }
                if (str_contains($sql, 'mfa_last_used_step')) {
                    return $advance ? self::ADMIN : false;
                }
                if (str_contains($sql, 'mfa_secret')) {
                    return $hasSecret ? 'present' : false;
                }

                return 0;
            });

            return $statement;
        });

        return $pdo;
    }

    private function warm(ScriptPdo $pdo): void
    {
        $pdo->onPrepare('pc_warm_changes', [['c' => '1']]);
        $statement = $pdo->prepare('pc_warm_changes');
        if ($statement === false) {
            self::fail('the audit connection could not be warmed');
        }
        $statement->execute();
    }

    private function audit(PDO $pdo): \PerfectConsign\Feature\Admin\AdminAudit
    {
        if ($pdo instanceof ScriptPdo) {
            $this->warm($pdo);
        }

        return new \PerfectConsign\Feature\Admin\AdminAudit(
            $pdo,
            new Request('POST', '/', [], [], '', '', '127.0.0.1'),
            new RequestId('test-request'),
        );
    }

    private function failures(PDO $pdo): AdminMfaFailures
    {
        return new AdminMfaFailures(
            $pdo,
            new Request('POST', '/', [], [], '', '', '127.0.0.1'),
            new NullLogger(),
            $this->audit($pdo),
            new SignInFailureRetention($pdo),
        );
    }
}

/**
 * The recovery-code list is present for the route check and gone on the next read.
 */
final class ExpiringCodeSession extends BagSession
{
    private int $codeReads = 0;

    public function get(string $key): mixed
    {
        if ($key === AdminMfaPending::CODES) {
            $this->codeReads++;
            if ($this->codeReads > 1) {
                return null;
            }
        }

        return parent::get($key);
    }
}

/**
 * An env file that is visible to is_file and then refuses a read or a write.
 */
final class EnvFileProbe
{
    public $context;

    private string $mode = 'r';

    private string $buffer = "KEEP=1\n";

    private int $position = 0;

    public static function register(): void
    {
        if (!in_array('envprobe', stream_get_wrappers(), true)) {
            stream_wrapper_register('envprobe', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($options, $openedPath);
        $this->mode = $mode;
        if (str_contains($path, 'deny-read') && !str_contains($mode, 'w')) {
            return false;
        }
        if (str_contains($path, 'deny-write') && (str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, 'c'))) {
            return false;
        }

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function stream_stat(): array
    {
        return self::stat();
    }

    public function url_stat(string $path, int $flags): array
    {
        unset($path, $flags);

        return self::stat();
    }

    /**
     * @return array<string, int>
     */
    private static function stat(): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 8,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => 512,
            'blocks' => 1,
        ];
    }
}
