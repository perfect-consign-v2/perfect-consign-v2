<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\LineGap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\CredentialStamp;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\EmailSignIn;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\MissingPage;
use PerfectConsign\Bootstrap\OwnerGate;
use PerfectConsign\Bootstrap\PostedRefusal;
use PerfectConsign\Bootstrap\PriorClose;
use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Bootstrap\SignInDirectory;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Bootstrap\StaffSignIn;
use PerfectConsign\Bootstrap\TenantLookup;
use PerfectConsign\Feature\Admin\AdminApp;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminAuditController;
use PerfectConsign\Feature\Admin\AdminResetMail;
use PerfectConsign\Feature\Admin\AdminResetTokens;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSessionController;
use PerfectConsign\Feature\Admin\AdminEnterAs;
use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Feature\Admin\AdminTenantController;
use PerfectConsign\Feature\Admin\AdminTenantList;
use PerfectConsign\Feature\Admin\AdminTenantWipe;
use PerfectConsign\Feature\Admin\AppLogFile;
use PerfectConsign\Feature\Book\MissedSaleQuote;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\ScriptPdo;
use PDO;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

final class ListedLineCoverageTest extends TestCase
{
    private bool $handlerOpen = false;

    protected function tearDown(): void
    {
        if ($this->handlerOpen) {
            restore_error_handler();
            $this->handlerOpen = false;
        }
        parent::tearDown();
    }

    private const SLUG = 'gapmall';

    private const TENANT = '01900000-0000-7000-8000-0000000000bb';

    private const ACCOUNT = '01900000-0000-7000-8000-0000000000aa';

    private const OTHER = '01900000-0000-7000-8000-0000000000cc';

    private const SCHEMA = 'tenant_abababababababababababababababab';

    public function testPostedRegisterMatchesSwitchesOrRefuses(): void
    {
        $calls = 0;
        $register = $this->register(function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return [
                    ['id' => 'drawer-1', 'name' => 'Front'],
                    ['id' => 'drawer-2', 'name' => 'Back'],
                ];
            }

            return [['id' => 'drawer-9', 'name' => 'Side']];
        });
        self::assertSame('drawer-9', $register->posted(self::SLUG, 'drawer-9', true));

        $same = $this->register(static fn (): array => [['id' => 'only', 'name' => 'Front']]);
        self::assertSame('only', $same->posted(self::SLUG, 'only', true));

        $refused = $this->register(static fn (): array => [['id' => 'only', 'name' => 'Front']]);
        try {
            $refused->posted(self::SLUG, 'other', true);
            self::fail('a second drawer was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose the active register.', $error->getMessage());
        }
    }

    public function testEmailSignInRefusesABadAddressAndRethrowsALock(): void
    {
        $logger = new NullLogger();
        $request = new Request('POST', '/', [], [], '', '', '203.0.113.9');
        $signIn = new EmailSignIn(
            $this->countPdo(0),
            $request,
            $logger,
            new SignInDirectory($this->directoryPdo()),
            $this->frontDoor($this->lockPdo(), $request, $logger),
            new SignInFailureRetention($this->countPdo(0)),
        );
        self::assertFalse($signIn->attempt('not-an-email', 'secret'));
        try {
            $signIn->attempt('owner@example.test', 'secret');
            self::fail('a locked sign-in continued');
        } catch (SignInLocked $locked) {
            self::assertSame('Too many sign-in attempts. Try again in a few minutes.', $locked->getMessage());
        }
    }

    public function testLoginSessionStampRewritesTheCredentialCookie(): void
    {
        $bag = [];
        $session = $this->session($bag);
        (new LoginSession($session))->stamp('4');
        self::assertSame('4', $bag['credentials_version'] ?? null);
        self::assertIsString($bag['csrf'] ?? null);
        self::assertNotSame('', $bag['csrf']);
    }

    public function testMallAppRedirectsAPriorShiftAClosedRentFloorAForeignSlugAndSignsIn(): void
    {
        $bag = $this->ownerBag();
        $bag['close_prior:' . self::SLUG] = 'yes';
        $session = $this->session($bag);
        $pdo = $this->mallPdo(true);
        $request = new Request('GET', '/' . self::SLUG . '/dashboard', [], ['csrf' => 'csrf-token']);
        $app = $this->mallApp($pdo, $session, $request);
        $app->open(self::SLUG, true);
        $this->sent(static function () use ($app): void {
            $app->show('Feature/Book/journals', ['slug' => self::SLUG, 'title' => 'Money record', 'journals' => []]);
        }, 'redirect');

        $rentBag = $this->ownerBag();
        $rentSession = $this->session($rentBag);
        $rent = new Request('GET', '/' . self::SLUG . '/rent', [], []);
        $rentApp = $this->mallApp($this->mallPdo(false), $rentSession, $rent);
        $this->sent(static function () use ($rentApp): void {
            $rentApp->open(self::SLUG, true);
        }, 'redirect');
        self::assertSame('This mall does not charge booth rent.', $rentBag['warning'] ?? null);

        $foreign = $this->mallApp($pdo, $session, $request);
        $this->sent(static function () use ($foreign): void {
            $foreign->open('other-mall', true);
        }, 'not found');

        self::assertFalse($app->signIn(self::SLUG, 'nope', 'nope'));
    }

    public function testMissingPageNamesAMallAndLogsAnUnknownRoute(): void
    {
        $bag = [];
        $session = $this->session($bag);
        $logger = new CountingLogger();
        $request = new Request('GET', '/no-such', [], []);
        $page = new MissingPage($session, $logger, new View($this->src()), $request, new TenantLookup($this->countPdo(0)));
        $this->sent(static function () use ($page): void {
            $page->missingMall('csrf-token');
        }, 'not found');
        $this->sent(static function () use ($page): void {
            $page->render(MallApp::MISS_ROUTE, 'csrf-token');
        }, 'not found');
        self::assertSame(1, $logger->debugs);
    }

    public function testOwnerGateRefusesAMissingMallABadNameALockAndAnUnverifiedEmail(): void
    {
        $request = new Request('POST', '/gapmall/login', [], [], '', '', '203.0.113.10');
        $logger = new NullLogger();
        $missing = $this->ownerGate($this->countPdo(0), $request, $logger, false);
        $this->sent(static function () use ($missing): void {
            $missing->signIn('missing-mall', 'owner', 'secret');
        }, 'not found');

        $gate = $this->ownerGate($this->ownerPdo(0, true, 't'), $request, $logger, true);
        self::assertFalse($gate->signIn(self::SLUG, 'Bad Name', 'secret'));

        $locked = $this->ownerGate($this->ownerPdo(5, true, 'f'), $request, $logger, true);
        try {
            $locked->signIn(self::SLUG, 'owner', 'secret');
            self::fail('a locked owner continued');
        } catch (SignInLocked $error) {
            self::assertSame('Too many sign-in attempts. Try again in a few minutes.', $error->getMessage());
        }

        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $unverified = $this->ownerGate($this->ownerPdo(0, false, 'true', $hash), $request, $logger, true);
        try {
            $unverified->signIn(self::SLUG, 'owner', 'secret');
            self::fail('an unverified owner continued');
        } catch (\PerfectConsign\Bootstrap\SignInUnverified $error) {
            self::assertSame('Confirm your email before signing in.', $error->getMessage());
        }

        $falseFlag = $this->ownerGate($this->ownerPdo(0, true, '0', $hash), $request, $logger, true);
        self::assertFalse($falseFlag->signIn(self::SLUG, 'owner', 'secret'));
        $numeric = $this->ownerGate($this->ownerPdo(0, true, 0, $hash), $request, $logger, true);
        self::assertFalse($numeric->signIn(self::SLUG, 'owner', 'secret'));
        $word = $this->ownerGate($this->ownerPdo(0, true, 'false', $hash), $request, $logger, true);
        self::assertFalse($word->signIn(self::SLUG, 'owner', 'secret'));
    }

    public function testFeatureManifestRefusesAnEmptyListABlockedDirectoryAndAFailedRename(): void
    {
        $root = $this->temp('pc-manifest-');
        $feature = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $this->mkdir($feature);
        RegisterFeatures::clearCache();
        try {
            RegisterFeatures::buildManifest($feature);
            self::fail('an empty feature list was written');
        } catch (RuntimeException $error) {
            self::assertSame('feature controller list is empty', $error->getMessage());
        }

        $controller = $feature . DIRECTORY_SEPARATOR . 'GapController.php';
        $written = file_put_contents($controller, "<?php\ndeclare(strict_types=1);\nnamespace Gap;\nfinal class GapController {}\n");
        self::assertNotFalse($written);
        $varFile = $root . DIRECTORY_SEPARATOR . 'var';
        self::assertNotFalse(file_put_contents($varFile, 'not-a-directory'));
        RegisterFeatures::clearCache();
        $previous = set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            RegisterFeatures::buildManifest($feature);
            self::fail('a file blocked the manifest directory');
        } catch (RuntimeException $error) {
            self::assertSame('feature controller manifest could not be written', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        self::assertTrue(unlink($varFile));

        $cache = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
        $this->mkdir($cache);
        $manifest = $cache . DIRECTORY_SEPARATOR . 'feature-controllers.php';
        self::assertNotFalse(file_put_contents($manifest, "<?php\n\nreturn [];\n"));
        chmod($manifest, 0444);
        RegisterFeatures::clearCache();
        $previous = set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            RegisterFeatures::buildManifest($feature);
            self::fail('a locked manifest was replaced');
        } catch (RuntimeException $error) {
            self::assertSame('feature controller manifest could not be written', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        chmod($manifest, 0666);
        self::assertTrue(unlink($manifest));
        $this->mkdir($manifest);
        RegisterFeatures::clearCache();
        $previous = set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            RegisterFeatures::buildManifest($feature);
            self::fail('a directory was replaced by the manifest');
        } catch (RuntimeException $error) {
            self::assertSame('feature controller manifest could not be written', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->removeTree($root);
    }

    public function testADiscountAboveBothCeilingsIsRefused(): void
    {
        $hash = password_hash('override', PASSWORD_DEFAULT);
        $statements = new class ($hash, self::OTHER) extends Statements {
            public function __construct(private string $hash, private string $other)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function rows(string $sql): array
            {
                return [['require_second_signature' => true]];
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, 'stall_override_attempts')) {
                    return [['attempts' => 0]];
                }
                if (str_contains($sql, 'discount_ceiling_bps')) {
                    return [['ceiling' => 0]];
                }
                if (str_contains($sql, 'FROM staff')) {
                    return [['id' => $this->other, 'password_hash' => $this->hash]];
                }

                return [];
            }
        };
        $bag = [
            'owner_slug' => self::SLUG,
            'actor_role' => 'cashier',
            'actor_account' => self::ACCOUNT,
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ];
        $ceiling = new SaleCeiling(
            $statements,
            new Actor($this->session($bag)),
            new AuditLog($statements),
            new Request('POST', '/' . self::SLUG . '/pos', [], [], '', '', '203.0.113.11'),
        );
        try {
            $ceiling->discount(10000, 'other', 'override', self::TENANT);
            self::fail('a discount above both ceilings was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that amount is above the ceiling', $error->getMessage());
        }
    }

    public function testTheDirectoryRefusesARoleThatCannotBeReset(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(false);
        $directory = new SignInDirectory($pdo);
        try {
            $directory->find('owner@example.test');
            self::fail('a stuck database role was ignored');
        } catch (RuntimeException $error) {
            self::assertSame('the database role could not be reset', $error->getMessage());
        }
    }

    public function testStaffSignInFlagsRolesAndSchemas(): void
    {
        $logger = new NullLogger();
        $unused = [];
        $sessions = new LoginSession($this->session($unused));
        $staff = new StaffSignIn($this->execPdo(false), $logger, $sessions, new SignInFailureRetention($this->execPdo(true)));
        $flag = new ReflectionMethod(StaffSignIn::class, 'flag');
        self::assertTrue($flag->invoke($staff, 't'));
        self::assertTrue($flag->invoke($staff, 'true'));
        self::assertTrue($flag->invoke($staff, 1));
        self::assertTrue($flag->invoke($staff, '1'));
        self::assertFalse($flag->invoke($staff, 'f'));
        self::assertFalse($flag->invoke($staff, 'false'));
        self::assertFalse($flag->invoke($staff, 0));
        self::assertFalse($flag->invoke($staff, '0'));
        self::assertNull($flag->invoke($staff, 'yes'));

        $tenant = new TenantRecord(self::TENANT, self::SLUG, self::SCHEMA, 'Bad-Role', 'Gap');
        $asRole = new ReflectionMethod(StaffSignIn::class, 'asTenantRole');
        try {
            $asRole->invoke($staff, $tenant, static fn (): bool => true);
            self::fail('a bad mall role was used');
        } catch (RuntimeException $error) {
            self::assertSame('the mall role is not usable', $error->getMessage());
        }
        $usable = new TenantRecord(self::TENANT, self::SLUG, self::SCHEMA, 'poster_gap', 'Gap');
        try {
            $asRole->invoke($staff, $usable, static fn (): bool => true);
            self::fail('a failed role switch was ignored');
        } catch (RuntimeException $error) {
            self::assertSame('the mall role could not be used', $error->getMessage());
        }
        $schema = new ReflectionMethod(StaffSignIn::class, 'schema');
        try {
            $schema->invoke($staff, 'Not A Schema');
            self::fail('a bad schema was used');
        } catch (RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }
    }

    public function testAdminAuditTokensSessionAndExpiredForm(): void
    {
        $request = new Request('POST', '/admin/login', [], ['csrf' => 'nope'], '', '', '203.0.113.12');
        $audit = new AdminAudit($this->auditPdo(0), $request);
        try {
            $audit->record(null, '', 'admin.sign_in', 'ok');
            self::fail('an empty audit row was stored');
        } catch (RuntimeException $error) {
            self::assertSame('the admin audit row is incomplete', $error->getMessage());
        }
        try {
            $audit->record(self::ACCOUNT, 'admin@example.test', 'admin.sign_in', 'ok');
            self::fail('a missing audit row was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('the admin audit row was not stored', $error->getMessage());
        }

        $signed = [
            AdminSession::ID => self::ACCOUNT,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => (string) time(),
            'platform_last_seen_at' => (string) time(),
        ];
        $reader = new AdminAuditController(
            $this->adminApp($this->session($signed), $request, $this->adminRowPdo()),
            $this->queryFalsePdo(),
        );
        try {
            $reader->index();
            self::fail('a missing audit query continued');
        } catch (RuntimeException $error) {
            self::assertSame('the admin audit log could not be read', $error->getMessage());
        }

        $tokens = new AdminResetTokens($this->throwingPdo());
        try {
            $tokens->issue(self::ACCOUNT);
            self::fail('a failed token insert was kept');
        } catch (RuntimeException $error) {
            self::assertSame('insert failed', $error->getMessage());
        }

        $unused = [];
        $admins = new AdminSession($this->session($unused));
        try {
            $admins->snapshot();
            self::fail('a missing admin session was snapshotted');
        } catch (RuntimeException $error) {
            self::assertSame('the platform admin session is missing', $error->getMessage());
        }
        $partial = [
            AdminSession::ID => self::ACCOUNT,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => 'nope',
            'platform_last_seen_at' => '',
        ];
        $snap = (new AdminSession($this->session($partial)))->snapshot();
        self::assertArrayHasKey('signed_in_at', $snap);
        self::assertArrayHasKey('last_seen_at', $snap);

        $guestBag = [];
        $guest = $this->adminApp($this->session($guestBag), $request);
        ob_start();
        try {
            $guest->checkCsrf();
            self::fail('an expired admin form continued');
        } catch (ResponseSent $sent) {
            self::assertSame('csrf', $sent->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    public function testAdminSignInHomeAndALockedSubmit(): void
    {
        $bag = [
            AdminSession::ID => self::ACCOUNT,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => (string) time(),
            'platform_last_seen_at' => (string) time(),
            'csrf' => 'csrf-token',
        ];
        $session = $this->session($bag);
        $request = new Request('GET', '/admin', [], []);
        $app = $this->adminApp($session, $request, $this->adminRowPdo());
        $signIn = new AdminSignIn(
            $this->countPdo(0),
            $request,
            new NullLogger(),
            new AdminSession($session),
            new AdminAudit($this->auditPdo(1), $request),
            new SignInFailureRetention($this->countPdo(0)),
        );
        self::assertFalse($signIn->attempt('not-an-email', 'secret'));
        $controller = new AdminSessionController($app, $signIn, new LoginSession($session));
        $this->sent(static function () use ($controller): void {
            $controller->home();
        }, 'redirect');
        $form = new Request('GET', '/admin/login', [], []);
        $formApp = $this->adminApp($session, $form, $this->adminRowPdo());
        $formController = new AdminSessionController($formApp, $signIn, new LoginSession($session));
        $this->sent(static function () use ($formController): void {
            $formController->form();
        }, 'redirect');

        $lockedRequest = new Request('POST', '/admin/login', [], [
            'csrf' => 'csrf-token',
            'email' => 'admin@example.test',
            'password' => 'secret',
        ], '', '', '203.0.113.13');
        $emptyBag = ['csrf' => 'csrf-token'];
        $empty = $this->session($emptyBag);
        $lockedApp = $this->adminApp($empty, $lockedRequest);
        $lockedSignIn = new AdminSignIn(
            $this->countPdo(40),
            $lockedRequest,
            new NullLogger(),
            new AdminSession($empty),
            new AdminAudit($this->auditPdo(1), $lockedRequest),
            new SignInFailureRetention($this->countPdo(0)),
        );
        $locked = new AdminSessionController($lockedApp, $lockedSignIn, new LoginSession($empty));
        ob_start();
        try {
            $locked->submit();
        } finally {
            ob_end_clean();
        }
        self::assertNull($empty->get(AdminSession::ID));
    }

    public function testResetMailRefusesAColdMailboxAndAFailedSend(): void
    {
        $mail = new AdminResetMail(
            new AdminResetTokens($this->countPdo(0)),
            new PlatformMailbox(new OutboundMail(new NullLogger()), new GmailSender(new PlatformOperatorNotice(new NullLogger())), new PlatformOperatorNotice(new NullLogger())),
            new NullLogger(),
        );
        $row = ['id' => self::ACCOUNT, 'email' => 'admin@example.test', 'hash' => 'hash'];
        $this->withEnv(['STALL_MAIL_FILE' => null, 'STALL_GMAIL_ADDRESS' => null, 'STALL_GMAIL_APP_PASSWORD' => null, 'STALL_SMTP_HOST' => null], function () use ($mail, $row): void {
            try {
                $mail->send($row, 'https://admin.example.test');
                self::fail('a cold mailbox sent mail');
            } catch (RuntimeException $error) {
                self::assertSame('the reset email could not be sent', $error->getMessage());
            }
        });
        $this->withEnv(['STALL_MAIL_FILE' => sys_get_temp_dir()], function () use ($mail, $row): void {
            try {
                $mail->send($row, 'https://admin.example.test');
                self::fail('a directory accepted the reset letter');
            } catch (RuntimeException $error) {
                self::assertSame('the reset email could not be sent', $error->getMessage());
            }
        });
    }

    public function testAppLogFailuresUseAStandInPath(): void
    {
        $previous = set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        if (!in_array('flipstat', stream_get_wrappers(), true)) {
            stream_wrapper_register('flipstat', FlipStatStream::class);
        }
        FlipStatStream::$stats = 0;
        $log = new AppLogFile();
        $log->usePath('flipstat://size');
        $previous = set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            $log->byteCount();
            self::fail('a missing size was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('The application log size could not be read.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        unset($previous);

        $missing = new AppLogFile();
        $missing->usePath($this->temp('pc-nolog-') . DIRECTORY_SEPARATOR . 'missing.log');
        self::assertFalse($missing->snapshot()['exists']);

        $blocked = $this->temp('pc-logblock-');
        $parent = $blocked . DIRECTORY_SEPARATOR . 'not-dir';
        self::assertNotFalse(file_put_contents($parent, 'x'));
        $clear = new AppLogFile();
        $clear->usePath($parent . DIRECTORY_SEPARATOR . 'app.log');
        try {
            $clear->clear();
            self::fail('a file was used as the log directory');
        } catch (RuntimeException $error) {
            self::assertSame('The log directory could not be created.', $error->getMessage());
        }

        $dir = $this->temp('pc-logdir-');
        $asDir = $dir . DIRECTORY_SEPARATOR . 'app.log';
        $this->mkdir($asDir);
        $stuck = new AppLogFile();
        $stuck->usePath($asDir);
        try {
            $stuck->clear();
            self::fail('a directory was cleared as a log');
        } catch (RuntimeException $error) {
            self::assertSame('The application log could not be cleared.', $error->getMessage());
        }

        $mtime = new ReflectionMethod(AppLogFile::class, 'modifiedAtUtc');
        try {
            $mtime->invoke($missing, $missing->path());
            self::fail('a missing log had a time');
        } catch (RuntimeException $error) {
            self::assertSame('The application log time could not be read.', $error->getMessage());
        }

        $read = new ReflectionMethod(AppLogFile::class, 'readTail');
        try {
            $read->invoke($stuck, $asDir, 10, 4);
            self::fail('a directory was read as a log');
        } catch (RuntimeException $error) {
            self::assertSame('The application log could not be opened.', $error->getMessage());
        }

        if (!in_array('flipread', stream_get_wrappers(), true)) {
            stream_wrapper_register('flipread', FlipReadStream::class);
        }
        FlipReadStream::$mode = 'seek';
        try {
            $read->invoke($stuck, 'flipread://log', 20, 4);
            self::fail('a stuck seek was ignored');
        } catch (RuntimeException $error) {
            self::assertSame('The application log could not be read.', $error->getMessage());
        }
        FlipReadStream::$mode = 'read';
        try {
            $read->invoke($stuck, 'flipread://log', 8, 8);
            self::fail('a failed read was ignored');
        } catch (RuntimeException $error) {
            self::assertSame('The application log could not be read.', $error->getMessage());
        }
        $this->removeTree($blocked);
        $this->removeTree($dir);
        restore_error_handler();
    }

    public function testAMissedSaleWithoutAPriceIsRefused(): void
    {
        try {
            MissedSaleQuote::vendorShare(0, 0);
            self::fail('a free missed sale was priced');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a missed sale owes the vendor', $error->getMessage());
        }
    }

    public function testJournalTemplatesRenderTheCertificateLinkAndTheConsignorMemo(): void
    {
        $view = new View($this->src());
        ob_start();
        try {
            $view->render('Feature/Book/journal', [
                'slug' => self::SLUG,
                'csrf' => 'csrf-token',
                'journal' => [
                    'journal_no' => '1',
                    'posting_key' => 'gift:1',
                    'source_type' => 'gift_certificate_issue',
                    'source_reference' => 'gc',
                    'posting_date' => '2026-01-02',
                    'description' => 'Gift',
                    'is_reversal' => false,
                    'id' => self::TENANT,
                ],
                'lines' => [],
                'canVoid' => false,
            ]);
            $journal = ob_get_contents();
            $view->render('Feature/Book/journals', [
                'slug' => self::SLUG,
                'journals' => [[
                    'journal_no' => '2',
                    'posting_date' => '2026-01-02',
                    'source_type' => 'vendor_memo',
                    'id' => self::ACCOUNT,
                ]],
                'consignmentOnly' => true,
            ]);
            $journals = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertIsString($journal);
        self::assertStringContainsString('Print certificate', $journal);
        self::assertIsString($journals);
        self::assertStringContainsString('Consignor memo', $journals);
    }

    public function testLocalAdminRefusesANonLocalPortBeforeItConnects(): void
    {
        $this->withEnv(['STALL_DB_PORT' => '1'], function (): void {
            if (getenv('STALL_DB_PORT') !== '1') {
                self::fail('refusing to open the local admin while the port is still 5436');
            }
            try {
                \PerfectConsign\Feature\Dev\LocalAdmin::open();
                self::fail('a non-local database was opened');
            } catch (RuntimeException $error) {
                self::assertSame('test data reset is only allowed on the local consign database', $error->getMessage());
            }
        });
    }

    public function testBookBrandCatalogCloseCompanyAndExportBranches(): void
    {
        $bag = $this->ownerBag();
        $session = $this->session($bag);
        $pdo = $this->mallPdo(true);
        $request = new Request('POST', '/' . self::SLUG . '/periods', [], [
            'csrf' => 'csrf-token',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-31',
        ]);
        $app = $this->mallApp($pdo, $session, $request);
        $ledger = $this->createStub(\PerfectConsign\Ledger\LedgerStore::class);
        $ledger->method('addPeriod');
        $ledger->method('roleAccount')->willReturn(self::ACCOUNT);
        $ledger->method('post')->willReturn(self::TENANT);
        $ledger->method('journal')->willReturn([
            'source_type' => 'sale',
            'source_reference' => 'sale-1',
            'posting_key' => 'sale:1',
            'posting_date' => '2020-01-01',
            'is_reversal' => false,
            'journal_no' => '9',
            'description' => 'Sale',
            'id' => self::TENANT,
        ]);
        $ledger->method('lines')->willReturn([]);
        $ledger->method('reversed')->willReturn(false);
        $book = new \PerfectConsign\Feature\Book\BookController($app, $ledger, new \PerfectConsign\Feature\Book\OpenGiftCertificates(new Statements($pdo)));
        $this->sent(static function () use ($book): void {
            $book->addPeriod(self::SLUG);
        }, 'redirect');

        $voidRequest = new Request('POST', '/' . self::SLUG . '/journals/' . self::TENANT . '/void', [], [
            'csrf' => 'csrf-token',
            'register_session_id' => 'missing',
            'posting_date' => '1999-01-01',
        ]);
        $voidBag = $this->ownerBag();
        $voidSession = $this->session($voidBag);
        $voidPdo = $this->mallPdo(true);
        $voidApp = $this->mallApp($voidPdo, $voidSession, $voidRequest);
        $statements = new Statements($voidPdo);
        $settings = new MallSettings($statements, new CompanyStore($statements, new DatabaseRole($voidPdo), $voidPdo, new CompanyLines()), new MallDay($statements));
        $clock = new MallClock($settings);
        $journals = new \PerfectConsign\Feature\Book\JournalController(
            $voidApp,
            $ledger,
            new \PerfectConsign\Feature\Register\RegisterStore($statements, $clock, new \PerfectConsign\Feature\Register\RegisterActivity($statements, $clock)),
            new ActiveRegister($voidPdo, $voidSession, $settings, $clock),
            $settings,
        );
        $date = new ReflectionMethod($journals, 'postingDate');
        try {
            $date->invoke($journals, ['posting_date' => '2020-01-01']);
            self::fail('a stray void date was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('void posting date must be the original date or today', $error->getMessage());
        }
        ob_start();
        try {
            $journals->void(self::SLUG, self::TENANT);
        } catch (ResponseSent) {
            // the drawer refusal is rendered or redirected
        } finally {
            ob_end_clean();
        }

        $zero = new class extends Statements {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function affected(string $sql, array $params): int
            {
                return 0;
            }

            public function rows(string $sql): array
            {
                return [];
            }

            public function bound(string $sql, array $params): array
            {
                return [['id' => '01900000-0000-7000-8000-0000000000aa', 'name' => 'Red']];
            }
        };
        $brands = new \PerfectConsign\Feature\Brand\BrandStore($zero, new \PerfectConsign\Shared\Database\CatalogLinks($zero));
        try {
            $brands->rename(self::ACCOUNT, 'Front');
            self::fail('a missing brand was renamed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That brand is not in this mall.', $error->getMessage());
        }
        try {
            $brands->remove(self::ACCOUNT);
            self::fail('a missing brand was deleted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That brand is not in this mall.', $error->getMessage());
        }
        $colors = \PerfectConsign\Feature\Catalog\NameList::fromPath('colors');
        self::assertInstanceOf(\PerfectConsign\Feature\Catalog\NameList::class, $colors);
        $lists = new \PerfectConsign\Feature\Catalog\NameListStore($zero, new \PerfectConsign\Shared\Database\CatalogLinks($zero));
        try {
            $lists->remove($colors, self::ACCOUNT);
            self::fail('a missing color was deleted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That color is not in this mall.', $error->getMessage());
        }
        self::assertSame('Red', $lists->named($colors, self::ACCOUNT)['name'] ?? null);

        $movement = new ScriptPdo();
        $movement->onPrepare('net_minor', [
            ['account_id' => self::ACCOUNT, 'net_minor' => '40'],
            ['account_id' => self::OTHER, 'net_minor' => '-15'],
        ]);
        $close = new \PerfectConsign\Feature\Close\CloseStore($movement, new Statements($movement));
        $rows = $close->periodMovement(self::TENANT);
        self::assertSame(40, $rows[0]['net_minor'] ?? null);
        self::assertSame(-15, $rows[1]['net_minor'] ?? null);

        $fields = [
            'name' => 'Gap',
            'street' => str_repeat('s', 201),
            'street2' => '',
            'city' => '',
            'state' => '',
            'postal' => '',
            'phone' => '',
            'email' => '',
        ];
        try {
            \PerfectConsign\Feature\Company\CompanyDraft::parse($fields);
            self::fail('a long street was stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that street is too long', $error->getMessage());
        }

        $exportHandler = set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        $export = new \PerfectConsign\Feature\Export\ExportStore(new ScriptPdo());
        if (!in_array('flipcsv', stream_get_wrappers(), true)) {
            stream_wrapper_register('flipcsv', FlipCsvStream::class);
        }
        $export->useBuffer('flipcsv://sheet');
        $guarded = new ReflectionMethod($export, 'guarded');
        FlipCsvStream::$open = false;
        try {
            $guarded->invoke($export, '=1+1');
            self::fail('a closed buffer wrote a formula');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the export returned a non-text line', $error->getMessage());
        }
        $export->useBuffer('flipcsv://sheet');
        FlipCsvStream::$open = true;
        FlipCsvStream::$write = false;
        try {
            $guarded->invoke($export, '=1+1');
            self::fail('a failed csv write was kept');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the export returned a non-text line', $error->getMessage());
        }
        FlipCsvStream::$write = true;
        FlipCsvStream::$read = false;
        try {
            $guarded->invoke($export, '=1+1');
            self::fail('a failed csv read was kept');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the export returned a non-text line', $error->getMessage());
        }
        restore_error_handler();
        unset($exportHandler);
    }

    public function testAdminTenantMissesRedirectAndAFailedList(): void
    {
        $now = (string) time();
        $bag = [
            'csrf' => 'csrf-token',
            'platform_admin_id' => self::ACCOUNT,
            'platform_admin_email' => 'admin@example.test',
            'platform_credentials_version' => '1',
            'platform_signed_in_at' => $now,
            'platform_last_seen_at' => $now,
        ];
        $session = $this->session($bag);
        $request = new Request('POST', '/admin/tenants/' . self::SLUG . '/enter', [], [
            'csrf' => 'csrf-token',
            'role' => 'owner',
        ]);
        $app = $this->adminApp($session, $request, $this->adminRowPdo());
        $empty = new ScriptPdo();
        $list = new AdminTenantList($empty, new DatabaseRole($empty));
        $enter = new AdminEnterAs(
            $empty,
            new DatabaseRole($empty),
            new LoginSession($session),
            new AdminSession($session),
            new AdminAudit($empty, $request),
            new Statements($empty),
        );
        $wipe = new AdminTenantWipe($empty, new AdminAudit($empty, $request), new \PerfectConsign\Feature\Backup\BackupStore($empty));
        $controller = new AdminTenantController($app, $list, $enter, $wipe);
        $this->sent(static function () use ($controller): void {
            $controller->enter(self::SLUG);
        }, 'redirect');
        $this->sent(static function () use ($controller): void {
            $controller->deleteForm(self::SLUG);
        }, 'redirect');
        $this->sent(static function () use ($controller): void {
            $controller->deleteSubmit(self::SLUG);
        }, 'redirect');

        $unreadable = $this->queryFalsePdo();
        $broken = new AdminTenantList($unreadable, new DatabaseRole($unreadable));
        try {
            $broken->all();
            self::fail('a failed tenant query was listed');
        } catch (RuntimeException $error) {
            self::assertSame('the tenant list could not be read', $error->getMessage());
        }
        $badSchema = new ScriptPdo();
        $badSchema->onQuery('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => 'not_a_schema',
            'display_name' => 'Gap',
        ]]);
        try {
            (new AdminTenantList($badSchema, new DatabaseRole($badSchema)))->all();
            self::fail('a bad schema was listed');
        } catch (RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }
        $schema = 'tenant_' . str_repeat('ab', 16);
        $unreadMode = new ScriptPdo();
        $unreadMode->onQuery('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => $schema,
            'display_name' => 'Gap',
        ]]);
        $unreadMode->onQuery('operating_mode', false);
        try {
            (new AdminTenantList($unreadMode, new DatabaseRole($unreadMode)))->all();
            self::fail('a missing mode was listed');
        } catch (RuntimeException $error) {
            self::assertSame('the mall mode could not be read', $error->getMessage());
        }
        $unknown = new ScriptPdo();
        $unknown->onQuery('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => $schema,
            'display_name' => 'Gap',
        ]]);
        $unknown->onQuery('operating_mode', [['operating_mode' => 'weird']]);
        try {
            (new AdminTenantList($unknown, new DatabaseRole($unknown)))->all();
            self::fail('an unknown mode was listed');
        } catch (RuntimeException $error) {
            self::assertSame('the mall operating mode is not recognized', $error->getMessage());
        }
        $both = new ScriptPdo();
        $both->onQuery('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => $schema,
            'display_name' => 'Gap',
        ]]);
        $both->onQuery('operating_mode', [['operating_mode' => 'both']]);
        $both->onPrepare('platform.owners', []);
        $listed = (new AdminTenantList($both, new DatabaseRole($both)))->all();
        self::assertSame('Consignment and booths', $listed[0]['modeLabel'] ?? null);

        $found = new ScriptPdo();
        $found->onPrepare('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => $schema,
            'display_name' => 'Gap',
        ]]);
        $hash = password_hash('second-secret', PASSWORD_DEFAULT);
        $wipePdo = $this->createStub(PDO::class);
        $wipePdo->method('prepare')->willReturnCallback(function (string $sql) use ($hash): \PDOStatement {
            $statement = $this->createStub(\PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function () use ($sql): bool {
                if (str_contains($sql, 'wipe_tenant')) {
                    throw new \PDOException('wipe refused');
                }

                return true;
            });
            $statement->method('fetch')->willReturn([
                'id' => self::OTHER,
                'email' => 'second@example.test',
                'password_hash' => $hash,
            ]);
            $statement->method('fetchColumn')->willReturnCallback(static function () use ($sql): mixed {
                if (str_contains($sql, 'latest_owner_backup')) {
                    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
                }

                return false;
            });
            $statement->method('rowCount')->willReturn(1);

            return $statement;
        });
        $deleteRequest = new Request('POST', '/admin/tenants/' . self::SLUG . '/delete', [], [
            'csrf' => 'csrf-token',
            'confirm_slug' => self::SLUG,
            'second_email' => 'second@example.test',
            'second_password' => 'second-secret',
        ]);
        $deleteApp = $this->adminApp($session, $deleteRequest, $this->adminRowPdo());
        $deleteList = new AdminTenantList($found, new DatabaseRole($found));
        $deleteWipe = new AdminTenantWipe($wipePdo, new AdminAudit($wipePdo, $deleteRequest), new \PerfectConsign\Feature\Backup\BackupStore($wipePdo));
        $delete = new AdminTenantController($deleteApp, $deleteList, $enter, $deleteWipe);
        ob_start();
        try {
            $delete->deleteSubmit(self::SLUG);
            $page = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertIsString($page);
        self::assertStringContainsString('wipe refused', $page);
    }

    public function testABackupExceptionBeforeHeadersShowsTheSignOutPage(): void
    {
        $bag = $this->ownerBag();
        $session = $this->session($bag);
        $pdo = $this->mallPdo(true);
        $request = new Request('POST', '/' . self::SLUG . '/backup', [], [
            'csrf' => 'csrf-token',
            'return' => 'sign-out',
        ]);
        $app = $this->mallApp($pdo, $session, $request);
        $statements = new Statements($pdo);
        $settings = new MallSettings($statements, new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()), new MallDay($statements));
        $logger = new NullLogger();
        $client = new \PerfectConsign\Feature\Backup\BackupClient(new \PerfectConsign\Feature\Backup\TenantBackup(
            $logger,
            new \PerfectConsign\Feature\Backup\PostgresTools(new \PerfectConsign\Feature\Backup\CommandProcess($logger)),
            new \PerfectConsign\Feature\Backup\BackupWorkspace($logger, new \PerfectConsign\Feature\Backup\DirectoryTree()),
            new \PerfectConsign\Feature\Backup\PlatformSnapshot(),
        ));
        $client->script(
            static function (\PerfectConsign\Feature\Backup\BackupConnection $connection, string $slug): \PerfectConsign\Feature\Backup\BackupFile {
                unset($connection, $slug);
                throw new \PerfectConsign\Feature\Backup\BackupException('The backup could not be taken.');
            },
            static function (\PerfectConsign\Feature\Backup\BackupFile $archive): bool {
                unset($archive);

                return false;
            },
            static function (\PerfectConsign\Feature\Backup\BackupFile $archive): void {
                unset($archive);
            },
        );
        $controller = new \PerfectConsign\Feature\Backup\BackupController(
            $app,
            new \PerfectConsign\Feature\Backup\BackupStore($pdo),
            $client,
            new \PerfectConsign\Feature\Mall\MallController(
                $app,
                $settings,
                new \PerfectConsign\Feature\Mall\MallPayoutSettings($statements),
                new \PerfectConsign\Feature\Backup\BackupStore($pdo),
                new \PerfectConsign\Feature\Backup\NightlyCopy(),
                new \PerfectConsign\Feature\Inventory\LabelCatalog(),
            ),
            $logger,
        );
        $controller->whenHeadersSent(static fn (): bool => false);
        ob_start();
        try {
            $controller->download(self::SLUG);
            $page = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertIsString($page);
        self::assertStringContainsString('The backup could not be taken.', $page);
    }

    public function testBackupFailuresAndStubbedDevResetsStayOffTheLivingMalls(): void
    {
        $noise = set_error_handler(static fn (int $severity): bool => in_array($severity, [E_WARNING, E_NOTICE], true));
        $this->handlerOpen = true;
        $logger = new NullLogger();
        $tree = new \PerfectConsign\Feature\Backup\DirectoryTree();
        $workspace = new \PerfectConsign\Feature\Backup\BackupWorkspace($logger, $tree);
        $blocked = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perfect-consign-backup-gapblock';
        if (is_dir($blocked)) {
            $this->removeTree($blocked);
        }
        self::assertNotFalse(file_put_contents($blocked, 'x'));
        try {
            $workspace->make('gapblock');
            self::fail('a file was used as a backup directory');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        }
        unlink($blocked);
        $dir = $this->temp('pc-write-');
        try {
            $workspace->write($dir, 'notes');
            self::fail('a directory accepted backup bytes');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        }
        $schema = $dir . DIRECTORY_SEPARATOR . 'schema.sql';
        $platform = $dir . DIRECTORY_SEPARATOR . 'platform.sql';
        $manifest = $dir . DIRECTORY_SEPARATOR . 'manifest.txt';
        self::assertNotFalse(file_put_contents($schema, 'schema'));
        self::assertNotFalse(file_put_contents($platform, 'platform'));
        self::assertNotFalse(file_put_contents($manifest, 'manifest'));
        $workspace->useZip(new BrokenZip());
        try {
            $workspace->zip($dir . DIRECTORY_SEPARATOR . 'backup.zip', $schema, $platform, $manifest);
            self::fail('a broken archive was stored');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        }

        $tool = $this->temp('pc-tool-') . DIRECTORY_SEPARATOR . 'not-a-real-tool.exe';
        self::assertNotFalse(file_put_contents($tool, 'not a pe image'));
        $process = new \PerfectConsign\Feature\Backup\CommandProcess($logger);
        try {
            $process->run([$tool], [], null, null, '');
            self::fail('a text file ran as a backup tool');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        }
        $process->omitPipes(0);
        try {
            $process->run([PHP_BINARY, '-r', 'echo 1;'], [], null, null, '');
            self::fail('a missing pipe was ignored');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        }

        $repo = dirname(__DIR__, 3);
        foreach ([$repo . DIRECTORY_SEPARATOR . 'public', $repo] as $root) {
            try {
                new \PerfectConsign\Feature\Backup\DailyBackupFiles($root);
                self::fail('a web-tree backup directory was accepted');
            } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
                self::assertSame('STALL_BACKUP_DIR must be outside the web tree', $error->getMessage());
            }
        }
        $daily = new \PerfectConsign\Feature\Backup\DailyBackupFiles($dir);
        $names = new ReflectionMethod($daily, 'names');
        try {
            $names->invoke($daily, $schema);
            self::fail('a file was listed as a backup directory');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup directory could not be read.', $error->getMessage());
        }
        $partial = $dir . DIRECTORY_SEPARATOR . 'tenant_' . str_repeat('ab', 16);
        $this->mkdir($partial);
        $partialFile = $partial . DIRECTORY_SEPARATOR . '2020-01-01.zip.partial';
        $this->mkdir($partialFile);
        $copy = new ReflectionMethod($daily, 'copyFile');
        try {
            $copy->invoke($daily, $schema, $partialFile);
            self::fail('a directory was copied onto');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be stored.', $error->getMessage());
        }

        $snapshot = new \PerfectConsign\Feature\Backup\PlatformSnapshot();
        $ownerRow = new ReflectionMethod($snapshot, 'ownerRow');
        try {
            $ownerRow->invoke($snapshot, 'nope');
            self::fail('a scalar owner row was accepted');
        } catch (\PerfectConsign\Feature\Backup\BackupException) {
        }
        try {
            $ownerRow->invoke($snapshot, ['id' => 1, 'username' => 'a', 'password_hash' => 'b', 'created_at' => 'c']);
            self::fail('a numeric owner id was accepted');
        } catch (\PerfectConsign\Feature\Backup\BackupException) {
        }
        try {
            $ownerRow->invoke($snapshot, ['id' => '1', 'username' => "a\n", 'password_hash' => 'b', 'created_at' => 'c']);
            self::fail('a broken owner name was accepted');
        } catch (\PerfectConsign\Feature\Backup\BackupException) {
        }
        $quote = new ReflectionMethod($snapshot, 'quote');
        $quoting = $this->createStub(PDO::class);
        $quoting->method('quote')->willReturn(false);
        try {
            $quote->invoke($snapshot, $quoting, 'gap');
            self::fail('an unquoted value was kept');
        } catch (\PerfectConsign\Feature\Backup\BackupException) {
        }

        $connection = new \PerfectConsign\Feature\Backup\BackupConnection('127.0.0.1', '1', 'consign', 'stall', 'secret');
        $tools = new \PerfectConsign\Feature\Backup\PostgresTools(new \PerfectConsign\Feature\Backup\CommandProcess($logger));
        $this->withEnv(['STALL_PG_DUMP' => 'docker:box'], function () use ($tools, $connection, $dir): void {
            $command = new ReflectionMethod($tools, 'command');
            try {
                $command->invoke($tools, 'pg_dump', $connection, [], null);
                self::fail('docker ran without a password file');
            } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
                self::assertSame('could not create the database password file', $error->getMessage());
            }
            $env = new ReflectionMethod($tools, 'dockerEnvFile');
            $created = $env->invoke($tools, $connection, $dir . DIRECTORY_SEPARATOR . 'missing-temp');
            if (is_string($created)) {
                chmod($created, 0444);
                $remove = new ReflectionMethod($tools, 'removeEnvFile');
                try {
                    $remove->invoke($tools, $created);
                } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
                    self::assertSame('could not remove the database password file', $error->getMessage());
                }
                chmod($created, 0666);
                if (is_file($created)) {
                    unlink($created);
                }
            }
        });

        $throwing = $this->createStub(PDO::class);
        $throwing->method('exec')->willReturn(0);
        $throwing->method('query')->willThrowException(new \PDOException('snapshot failed'));
        $backup = new \PerfectConsign\Feature\Backup\TenantBackup(
            $logger,
            $tools,
            $workspace,
            new \PerfectConsign\Feature\Backup\PlatformSnapshot(),
        );
        try {
            $backup->create($connection, self::SLUG, $throwing);
            self::fail('a failed snapshot was packaged');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        }
        $file = new \PerfectConsign\Feature\Backup\BackupFile('id', $dir, $dir, 1, str_repeat('a', 64), 'gap.zip', '2026-01-01T00:00:00Z');
        try {
            $backup->send($file);
            self::fail('a directory was sent as a zip');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be opened.', $error->getMessage());
        }
        $package = new ReflectionMethod($backup, 'package');
        try {
            $package->invoke($backup, $dir, self::SLUG, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'id');
            self::fail('a missing zip was packaged');
        } catch (\PerfectConsign\Feature\Backup\BackupException) {
        }
        if (!in_array('flipread', stream_get_wrappers(), true)) {
            stream_wrapper_register('flipread', FlipReadStream::class);
        }
        FlipReadStream::$mode = 'read';
        $handle = fopen('flipread://body', 'rb');
        self::assertIsResource($handle);
        $body = new ReflectionMethod($backup, 'writeBody');
        try {
            $body->invoke($backup, $handle);
            self::fail('a failed read was sent');
        } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
            self::assertSame('The backup could not be read.', $error->getMessage());
        }

        $this->withEnv(['STALL_DB_PORT' => '1'], function (): void {
            if (getenv('STALL_DB_PORT') !== '1') {
                self::fail('refusing to exercise reset while the database port is still 5436');
            }
            $pdo = new ScriptPdo();
            $pdo->onQuery('pg_roles', [['present' => '1']]);
            $pdo->onQuery('uuidv7', [['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']]);
            $pdo->onPrepare('password_hash', [[
                'username' => 'owner',
                'password_hash' => 'hash',
                'email' => 'owner@example.test',
            ]]);
            $dev = new \PerfectConsign\Feature\Dev\DevTenantReset();
            $dev->usePdo($pdo);
            try {
                $dev->load();
            } catch (\Throwable $error) {
                self::assertNotSame('', $error->getMessage());
            }
            $mall = new \PerfectConsign\Feature\Dev\MallTenantReset();
            $mall->usePdo($pdo);
            try {
                $mall->load();
            } catch (\Throwable $error) {
                self::assertNotSame('', $error->getMessage());
            }
            $shop = new \PerfectConsign\Feature\Dev\ShopTenantReset();
            try {
                $shop->rebuild($pdo);
            } catch (\Throwable $error) {
                self::assertNotSame('', $error->getMessage());
            }
        });
        $volume = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perfect_consign_pg_gap';
        $alias = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-gap-alias';
        $this->mkdir($volume);
        if (is_dir($alias)) {
            rmdir($alias);
        }
        exec('cmd /c mklink /J ' . escapeshellarg($alias) . ' ' . escapeshellarg($volume), $linkOutput, $linkStatus);
        if ($linkStatus === 0 && is_dir($alias)) {
            try {
                new \PerfectConsign\Feature\Backup\DailyBackupFiles($alias);
                self::fail('a junction into the database volume was accepted');
            } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
                self::assertSame('STALL_BACKUP_DIR must be outside the database volume', $error->getMessage());
            }
            $linked = $dir . DIRECTORY_SEPARATOR . 'schema-link.sql';
            if (symlink($schema, $linked)) {
                $holder = $dir . DIRECTORY_SEPARATOR . 'links';
                $this->mkdir($holder);
                rename($linked, $holder . DIRECTORY_SEPARATOR . 'schema-link.sql');
                $tree->remove($holder);
            }
            rmdir($alias);
        }
        unset($linkOutput, $noise);
        rmdir($volume);
        restore_error_handler();
        $this->handlerOpen = false;
        $this->removeTree($dir);
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $rows
     */
    private function register(callable $rows): ActiveRegister
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturnCallback(static fn (): array => $rows());
        $statement->method('execute')->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $mallRows = $this->createStub(Statements::class);
        $mallRows->method('rows')->willReturn([['time_zone' => 'UTC']]);
        $mall = new MallSettings($mallRows, new CompanyStore(
            $this->createStub(Statements::class),
            new DatabaseRole($pdo),
            $pdo,
            new CompanyLines(),
        ), new MallDay($mallRows));
        $bag = [];

        return new ActiveRegister($pdo, $this->session($bag), $mall, new MallClock($mall));
    }

    private function frontDoor(PDO $pdo, Request $request, LoggerInterface $logger): FrontDoor
    {
        $bag = [];
        $session = $this->session($bag);
        $view = new View($this->src());
        $tenants = new TenantLookup($pdo);
        $missing = new MissingPage($session, $logger, $view, $request, $tenants);
        $sessions = new LoginSession($session);

        return new FrontDoor(
            $missing,
            new PriorClose($session, $request),
            new OwnerGate($pdo, $session, $logger, $request, $tenants, $missing, new StaffSignIn($pdo, $logger, $sessions, new SignInFailureRetention($pdo)), $sessions),
            new PostedRefusal($logger),
            new Actor($session),
            new CredentialStamp($pdo, $session, new DatabaseRole($pdo)),
        );
    }

    private function ownerGate(PDO $pdo, Request $request, LoggerInterface $logger, bool $found): OwnerGate
    {
        $gateBag = [];
        $session = $this->session($gateBag);
        $lookup = $found ? $pdo : new ScriptPdo();
        $tenants = new TenantLookup($lookup);
        $missing = new MissingPage($session, $logger, new View($this->src()), $request, $tenants);
        $sessions = new LoginSession($session);

        return new OwnerGate(
            $pdo,
            $session,
            $logger,
            $request,
            $tenants,
            $missing,
            new StaffSignIn($pdo, $logger, $sessions, new SignInFailureRetention($pdo)),
            $sessions,
        );
    }

    public function testCatalogPagesAndPasswordFilesRefuseTheMissedBranches(): void
    {
        $bag = $this->ownerBag();
        $session = $this->session($bag);
        $disabled = $this->mallPdo(false, '0');
        $hidden = new Request('GET', '/' . self::SLUG . '/brands', [], []);
        $hiddenApp = $this->mallApp($disabled, $session, $hidden);
        $brands = new \PerfectConsign\Feature\Brand\BrandStore(
            new Statements($disabled),
            new \PerfectConsign\Shared\Database\CatalogLinks(new Statements($disabled)),
        );
        $hiddenMall = $this->mallSettings($disabled);
        $this->sent(function () use ($hiddenApp, $brands, $hiddenMall): void {
            (new \PerfectConsign\Feature\Brand\BrandController($hiddenApp, $brands, $hiddenMall))->show(self::SLUG);
        }, 'not found');

        $empty = new class extends Statements {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function affected(string $sql, array $params): int
            {
                return 0;
            }

            public function rows(string $sql): array
            {
                return [];
            }

            public function bound(string $sql, array $params): array
            {
                return [];
            }
        };
        $delete = new Request('POST', '/' . self::SLUG . '/brands/' . self::ACCOUNT . '/delete', [], [
            'csrf' => 'csrf-token',
        ]);
        $deleteBag = $this->ownerBag();
        $deleteSession = $this->session($deleteBag);
        $deletePdo = $this->mallPdo(false);
        $deleteApp = $this->mallApp($deletePdo, $deleteSession, $delete);
        $store = new \PerfectConsign\Feature\Brand\BrandStore($empty, new \PerfectConsign\Shared\Database\CatalogLinks($empty));
        $deleteMall = $this->mallSettings($deletePdo);
        $html = $this->capture(function () use ($deleteApp, $store, $deleteMall): void {
            (new \PerfectConsign\Feature\Brand\BrandController($deleteApp, $store, $deleteMall))->remove(self::SLUG, self::ACCOUNT);
        });
        self::assertStringContainsString('That brand is not in this mall.', $html);

        $edit = new Request('GET', '/' . self::SLUG . '/colors/' . self::ACCOUNT . '/edit', [], []);
        $editBag = $this->ownerBag();
        $editSession = $this->session($editBag);
        $editPdo = $this->mallPdo(false);
        $editApp = $this->mallApp($editPdo, $editSession, $edit);
        $lists = new \PerfectConsign\Feature\Catalog\NameListStore($empty, new \PerfectConsign\Shared\Database\CatalogLinks($empty));
        $editMall = $this->mallSettings($editPdo);
        $this->sent(function () use ($editApp, $lists, $editMall): void {
            (new \PerfectConsign\Feature\Catalog\NameListController($editApp, $lists, $editMall))->edit(self::SLUG, 'colors', self::ACCOUNT);
        }, 'not found');

        $named = new class extends Statements {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function rows(string $sql): array
            {
                return [];
            }

            public function bound(string $sql, array $params): array
            {
                return [['id' => '01900000-0000-7000-8000-0000000000aa', 'name' => 'Red']];
            }
        };
        $rename = new Request('GET', '/' . self::SLUG . '/colors', ['rename' => self::ACCOUNT], []);
        $renameBag = $this->ownerBag();
        $renameSession = $this->session($renameBag);
        $renamePdo = $this->mallPdo(false);
        $renameApp = $this->mallApp($renamePdo, $renameSession, $rename);
        $namedLists = new \PerfectConsign\Feature\Catalog\NameListStore($named, new \PerfectConsign\Shared\Database\CatalogLinks($named));
        $renameMall = $this->mallSettings($renamePdo);
        $colors = $this->capture(function () use ($renameApp, $namedLists, $renameMall): void {
            (new \PerfectConsign\Feature\Catalog\NameListController($renameApp, $namedLists, $renameMall))->show(self::SLUG, 'colors');
        });
        self::assertStringContainsString('Red', $colors);

        $deleteColor = new Request('POST', '/' . self::SLUG . '/colors/' . self::ACCOUNT . '/delete', [], [
            'csrf' => 'csrf-token',
        ]);
        $colorBag = $this->ownerBag();
        $colorSession = $this->session($colorBag);
        $colorPdo = $this->mallPdo(false);
        $colorApp = $this->mallApp($colorPdo, $colorSession, $deleteColor);
        $colorMall = $this->mallSettings($colorPdo);
        $refusedColor = $this->capture(function () use ($colorApp, $lists, $colorMall): void {
            (new \PerfectConsign\Feature\Catalog\NameListController($colorApp, $lists, $colorMall))->remove(self::SLUG, 'colors', self::ACCOUNT);
        });
        self::assertStringContainsString('That color is not in this mall.', $refusedColor);

        $companyRequest = new Request('POST', '/' . self::SLUG . '/company', [], [
            'csrf' => 'csrf-token',
            'store_name' => 'Gap',
            'street' => '1 Main',
            'street2' => '',
            'city' => '',
            'state' => '',
            'postal' => '',
            'phone' => '',
            'email' => '',
        ]);
        $companyBag = $this->ownerBag();
        $companySession = $this->session($companyBag);
        $companyPdo = $this->mallPdo(false);
        $companyApp = $this->mallApp($companyPdo, $companySession, $companyRequest);
        $refusing = new class($companyPdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function transaction(callable $work): void
            {
                throw new \PerfectConsign\Ledger\DatabaseRefusal('the books refused');
            }
        };
        $company = new \PerfectConsign\Feature\Company\CompanyController(
            $companyApp,
            new \PerfectConsign\Feature\Company\CompanyStore($refusing, new DatabaseRole($companyPdo), $companyPdo, new \PerfectConsign\Feature\Company\CompanyLines()),
            new AuditLog($refusing),
            new Actor($companySession),
        );
        $companyPage = $this->capture(function () use ($company): void {
            $company->save(self::SLUG);
        });
        self::assertStringContainsString('the books refused', $companyPage);

        $exportRequest = new Request('POST', '/' . self::SLUG . '/export', [], [
            'csrf' => 'csrf-token',
            'system' => 'quickbooks',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'tax_rate' => "tax\nrate",
        ]);
        $exportBag = $this->ownerBag();
        $exportSession = $this->session($exportBag);
        $exportPdo = $this->mallPdo(false);
        $exportApp = $this->mallApp($exportPdo, $exportSession, $exportRequest);
        $exports = new \PerfectConsign\Feature\Export\ExportStore($exportPdo);
        $exportPage = $this->capture(function () use ($exportApp, $exports): void {
            (new \PerfectConsign\Feature\Export\ExportController($exportApp, $exports))->download(self::SLUG);
        });
        self::assertStringContainsString('Tax rate label is too long', $exportPage);

        \PerfectConsign\Shared\Http\Csv::refuseRead(true);
        try {
            \PerfectConsign\Shared\Http\Csv::document(['amount'], []);
            self::fail('a closed csv buffer was read');
        } catch (\RuntimeException $error) {
            self::assertSame('could not read a csv buffer', $error->getMessage());
        }

        $tree = new \PerfectConsign\Feature\Backup\DirectoryTree();
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-link-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $file = $directory . DIRECTORY_SEPARATOR . 'note.txt';
        file_put_contents($file, 'note');
        $tree->treatAsLink(true);
        self::assertTrue($tree->remove($directory));
        self::assertFileDoesNotExist($file);

        $tools = new \PerfectConsign\Feature\Backup\PostgresTools(new \PerfectConsign\Feature\Backup\CommandProcess(new NullLogger()));
        $connection = new \PerfectConsign\Feature\Backup\BackupConnection('127.0.0.1', '5436', 'consign', 'stall', 'secret');
        $envFile = new ReflectionMethod($tools, 'dockerEnvFile');
        $remove = new ReflectionMethod($tools, 'removeEnvFile');
        $this->withEnv(['STALL_PG_DUMP' => 'docker:pg'], function () use ($tools, $connection, $envFile, $remove): void {
            foreach (['temp' => 'could not create the database password file', 'chmod' => 'could not protect the database password file', 'write' => 'could not write the database password file'] as $step => $message) {
                $tools->failPasswordFile($step);
                try {
                    $envFile->invoke($tools, $connection);
                    self::fail($step . ' was accepted');
                } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
                    self::assertSame($message, $error->getMessage());
                }
            }
            $tools->failPasswordFile('unlink');
            $path = $envFile->invoke($tools, $connection, sys_get_temp_dir());
            self::assertIsString($path);
            try {
                $remove->invoke($tools, $path);
                self::fail('a stuck password file was removed');
            } catch (\PerfectConsign\Feature\Backup\BackupException $error) {
                self::assertSame('could not remove the database password file', $error->getMessage());
            } finally {
                $tools->failPasswordFile('temp');
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });

        $port = getenv('STALL_DB_PORT');
        putenv('STALL_DB_PORT=1');
        try {
            (new \PerfectConsign\Feature\Dev\DemoLoginEnsure())->ensure();
            self::fail('a non-local database was opened');
        } catch (\RuntimeException $error) {
            self::assertSame('test data reset is only allowed on the local consign database', $error->getMessage());
        } finally {
            if ($port === false) {
                putenv('STALL_DB_PORT');
            } else {
                putenv('STALL_DB_PORT=' . $port);
            }
        }
    }

    private function mallApp(PDO $pdo, Session $session, Request $request): MallApp
    {
        $logger = new NullLogger();
        $view = new View($this->src());
        $tenants = new TenantLookup($pdo);
        $missing = new MissingPage($session, $logger, $view, $request, $tenants);
        $sessions = new LoginSession($session);
        $owner = new OwnerGate($pdo, $session, $logger, $request, $tenants, $missing, new StaffSignIn($pdo, $logger, $sessions, new SignInFailureRetention($pdo)), $sessions);
        $rows = new Statements($pdo);
        $settings = new MallSettings($rows, new CompanyStore($rows, new DatabaseRole($pdo), $pdo, new CompanyLines()), new MallDay($rows));

        return new MallApp(
            $pdo,
            $session,
            $view,
            $request,
            new OwnerAccess(),
            $settings,
            new ActiveRegister($pdo, $session, $settings, new MallClock($settings)),
            $tenants,
            new FrontDoor($missing, new PriorClose($session, $request), $owner, new PostedRefusal($logger), new Actor($session), new CredentialStamp($pdo, $session, new DatabaseRole($pdo))),
        );
    }

    private function adminApp(Session $session, Request $request, ?PDO $pdo = null): AdminApp
    {
        $pdo ??= $this->countPdo(0);

        return new AdminApp(
            $pdo,
            $session,
            new View($this->src()),
            $request,
            new NullLogger(),
            new AdminSession($session),
            new LoginSession($session),
        );
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function session(array &$bag): Session
    {
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = $value;
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
            unset($bag[$key]);
        });
        $session->method('has')->willReturnCallback(static function (string $key) use (&$bag): bool {
            return array_key_exists($key, $bag);
        });
        $session->method('regenerateId')->willReturnCallback(static function (): void {
        });
        $session->method('destroy')->willReturnCallback(static function () use (&$bag): void {
            $bag = [];
        });

        return $session;
    }

    /**
     * @return array<string, string>
     */
    private function ownerBag(): array
    {
        return [
            'csrf' => 'csrf-token',
            'owner_slug' => self::SLUG,
            'tenant_id' => self::TENANT,
            'owner_name' => 'Ada',
            'actor_role' => 'owner',
            'actor_account' => self::ACCOUNT,
            'credentials_version' => '1',
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ];
    }

    private function mallPdo(bool $rentals, string $brand = '1'): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onQuery('FROM mall', [[
            'time_zone' => 'UTC',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '40',
            'floor_depth_ft' => '40',
            'commission_bps' => '2500',
            'floor_days' => '90',
            'cash_round_minor' => '0',
            'tax_bps' => '0',
            'use_color' => '1',
            'use_category' => '0',
            'use_size' => '0',
            'use_condition' => '0',
            'use_department' => '0',
            'use_brand' => $brand,
            'label_stock' => 'avery_8160',
            'booth_rentals_enabled' => $rentals ? '1' : '0',
            'rent_cycle_day' => '1',
            'consignment_enabled' => '1',
            'store_stock_enabled' => '0',
            'receipt_footer' => '',
            'operating_mode' => 'booth_mall',
        ]]);
        $pdo->onPrepare('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => self::SCHEMA,
            'display_name' => 'Gap',
        ]]);
        $pdo->onPrepare('credentials_version', [['credentials_version' => '1']]);
        $pdo->onPrepare('register_sessions', [[
            'id' => self::ACCOUNT,
            'name' => 'Front',
            'opened_at' => '2000-01-01 00:00:00',
        ]]);
        $pdo->onPrepare('count(*)', [['count' => '0']]);

        return $pdo;
    }

    private function directoryPdo(): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('sign_in_directory', [[
            'slug' => self::SLUG,
            'username' => 'owner',
        ]]);

        return $pdo;
    }

    private function countPdo(int $count): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('count(*)', [['count' => (string) $count]]);

        return $pdo;
    }

    private function lockPdo(): ScriptPdo
    {
        $pdo = $this->countPdo(5);
        $pdo->onPrepare('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => self::SCHEMA,
            'display_name' => 'Gap',
        ]]);

        return $pdo;
    }

    /**
     * @param bool|string|int $flag
     */
    private function ownerPdo(int $failures, bool $verified, bool|string|int $flag, string $hash = 'hash'): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('count(*)', [['count' => (string) $failures]]);
        $pdo->onPrepare('FROM platform.owners', [[
            'id' => self::ACCOUNT,
            'password_hash' => $hash,
            'credentials_version' => '1',
            'email_verified_at' => $verified ? '2026-01-01' : null,
            'can_sign_in' => $flag,
        ]]);
        $pdo->onPrepare('platform.tenants', [[
            'id' => self::TENANT,
            'slug' => self::SLUG,
            'schema_name' => self::SCHEMA,
            'display_name' => 'Gap',
        ]]);

        return $pdo;
    }

    private function adminRowPdo(): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('platform.admins', [[
            'id' => self::ACCOUNT,
            'email' => 'admin@example.test',
            'credentials_version' => '1',
        ]]);

        return $pdo;
    }

    private function auditPdo(int $count): PDO
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn($count);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function queryFalsePdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);
        $pdo->method('prepare')->willReturn($this->createStub(\PDOStatement::class));

        return $pdo;
    }

    private function throwingPdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willThrowException(new RuntimeException('insert failed'));
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function execPdo(bool $ok): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn($ok ? 0 : false);

        return $pdo;
    }

    private function mallSettings(PDO $pdo): MallSettings
    {
        $rows = new Statements($pdo);

        return new MallSettings(
            $rows,
            new \PerfectConsign\Feature\Company\CompanyStore($rows, new DatabaseRole($pdo), $pdo, new \PerfectConsign\Feature\Company\CompanyLines()),
            new \PerfectConsign\Feature\Mall\MallDay($rows),
        );
    }

    /**
     * @param callable(): void $run
     */
    private function capture(callable $run): string
    {
        ob_start();
        try {
            $run();
        } finally {
            $body = ob_get_clean();
        }

        return is_string($body) ? $body : '';
    }

    /**
     * @param callable(): void $run
     */
    private function sent(callable $run, string $message): void
    {
        ob_start();
        try {
            $run();
            self::fail('the response continued');
        } catch (ResponseSent $sent) {
            self::assertSame($message, $sent->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    private function src(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
    }

    private function temp(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        $this->mkdir($path);

        return $path;
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            self::fail('could not create ' . $path);
        }
    }

    private function removeTree(string $root): void
    {
        if (is_file($root)) {
            unlink($root);

            return;
        }
        if (!is_dir($root)) {
            return;
        }
        $items = scandir($root);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($root . DIRECTORY_SEPARATOR . $item);
        }
        rmdir($root);
    }

    /**
     * @param array<string, string|null> $values
     * @param callable(): void $run
     */
    private function withEnv(array $values, callable $run): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $current = getenv($key);
            $previous[$key] = $current === false ? null : $current;
            if ($value === null) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        try {
            $run();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }
}

final class CountingLogger extends NullLogger
{
    public int $debugs = 0;

    public function debug(string|\Stringable $message, array $context = []): void
    {
        unset($message, $context);
        $this->debugs++;
    }
}

final class FlipStatStream
{
    public static int $stats = 0;

    /** @var resource|null */
    public $context;

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        unset($path, $flags);
        self::$stats++;
        if (self::$stats === 1) {
            return [
                'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1, 'uid' => 0, 'gid' => 0,
                'rdev' => 0, 'size' => 4, 'atime' => 0, 'mtime' => 0, 'ctime' => 0, 'blksize' => 0, 'blocks' => 0,
            ];
        }

        return false;
    }
}

final class BrokenZip extends \ZipArchive
{
    public function addFile(
        string $filepath,
        string $entryname = '',
        int $start = 0,
        int $length = 0,
        int $flags = \ZipArchive::FL_OVERWRITE,
    ): bool {
        unset($filepath, $entryname, $start, $length, $flags);

        return false;
    }
}

final class FlipCsvStream
{
    public static bool $open = true;

    public static bool $write = true;

    public static bool $read = true;

    public mixed $context = null;

    private string $body = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);

        return self::$open;
    }

    public function stream_write(string $data): int
    {
        if (!self::$write) {
            return 0;
        }
        $this->body .= $data;

        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        unset($count);
        if (!self::$read) {
            return false;
        }
        $body = $this->body;
        $this->body = '';

        return $body;
    }

    public function stream_eof(): bool
    {
        return $this->body === '';
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        unset($offset, $whence);

        return true;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => strlen($this->body)];
    }

    public function stream_close(): void
    {
    }
}

final class FlipReadStream
{
    public static string $mode = 'seek';

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => 20, 'mode' => 0100644];
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        unset($offset, $whence);

        return self::$mode !== 'seek';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_read(int $count): string|false
    {
        unset($count);

        return self::$mode === 'read' ? false : 'hello';
    }

    public function stream_close(): void
    {
    }
}
