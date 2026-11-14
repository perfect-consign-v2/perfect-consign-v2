<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use ArrayObject;
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
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Signup\OwnerEmailVerification;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Signup\SignupController;
use PerfectConsign\Feature\Signup\SignupRateLimit;
use PerfectConsign\Feature\Signup\StoreSignup;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SignupControllerTest extends TestCase
{
    public function testTheSignupFormFollowsThePlatformSwitch(): void
    {
        $off = $this->capture(function (): void {
            [$controller] = $this->controller($this->request('GET', []), $this->settings(false));
            $controller->show();
        });
        self::assertStringContainsString('Self-serve signup is turned off', $off);

        $on = $this->capture(function (): void {
            [$controller] = $this->controller($this->request('GET', []), $this->settings(true));
            $controller->show();
        });
        self::assertStringContainsString('name="store_name"', $on);
        self::assertStringNotContainsString('Self-serve signup is turned off', $on);
    }

    public function testAPostWhileSignupIsOffShowsTheClosedPage(): void
    {
        $html = $this->capture(function (): void {
            [$controller] = $this->controller($this->request('POST', [
                'csrf' => 'csrf-token',
                'store_name' => 'Harbor',
                'email' => 'owner@harbor.test',
                'password' => 'password1',
                'password_confirm' => 'password1',
            ]), $this->settings(false));
            $controller->create();
        });

        self::assertStringContainsString('Self-serve signup is turned off', $html);
    }

    public function testABadSignupShowsTheFormError(): void
    {
        $html = $this->capture(function (): void {
            [$controller] = $this->controller($this->request('POST', [
                'csrf' => 'csrf-token',
                'store_name' => 'Harbor',
                'email' => 'not-an-email',
                'password' => 'password1',
                'password_confirm' => 'password1',
            ]), $this->settings(true));
            $controller->create();
        });

        self::assertStringContainsString('Enter a valid email address.', $html);
        self::assertStringContainsString('name="store_name"', $html);
    }

    public function testAPostWithoutTheSessionTokenExpiresTheForm(): void
    {
        [$controller] = $this->controller($this->request('POST', [
            'store_name' => 'Harbor',
            'email' => 'owner@harbor.test',
            'password' => 'password1',
            'password_confirm' => 'password1',
        ]), $this->settings(true));
        ob_start();
        try {
            $controller->create();
            self::fail('an unsigned signup was accepted');
        } catch (ResponseSent $sent) {
            self::assertSame('csrf', $sent->getMessage());
        } finally {
            $html = ob_get_clean();
        }

        self::assertIsString($html);
        self::assertStringContainsString('The form expired', $html);
    }

    public function testAnUnusableConfirmationLinkReturnsToSignIn(): void
    {
        [$controller, $bag] = $this->controller($this->request('GET', []), $this->settings(false));
        try {
            $controller->verify('short-token');
            self::fail('a short token was accepted');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
        }

        self::assertSame('That confirmation link is invalid or expired.', $bag['warning'] ?? null);
    }

    public function testAConfirmedMailboxReturnsToSignIn(): void
    {
        [$controller, $bag] = $this->controller(
            $this->request('GET', []),
            $this->settings(false),
            $this->confirmingVerification(),
        );
        try {
            $controller->verify(str_repeat('a', 43));
            self::fail('a confirmed mailbox stayed on the verify page');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
        }

        self::assertSame('Email confirmed. Sign in to open your store.', $bag['notice'] ?? null);
    }

    public function testACreatedStoreAsksTheOwnerToConfirmEmail(): void
    {
        $previous = getenv('STALL_MAIL_FILE');
        $file = dirname(__DIR__, 3) . '/var/signup-coverage-mail.txt';
        putenv('STALL_MAIL_FILE=' . $file);
        try {
            $html = $this->capture(function (): void {
                $this->createdController(true, '01900000-0000-7000-8000-0000000000bb')->create();
            });
        } finally {
            $this->restoreMailFile($previous);
            if (is_file($file)) {
                unlink($file);
            }
        }

        self::assertStringContainsString('Check your email', $html);
    }

    public function testACreatedStoreWithoutAnOwnerIsRefused(): void
    {
        $html = $this->capture(function (): void {
            $this->createdController(true, false)->create();
        });

        self::assertStringContainsString('That store could not be created.', $html);
    }

    public function testAVerificationFailureAbandonsTheStore(): void
    {
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . sys_get_temp_dir());
        try {
            $html = $this->capture(function (): void {
                $this->createdController(true, '01900000-0000-7000-8000-0000000000bb')->create();
            });
        } finally {
            $this->restoreMailFile($previous);
        }

        self::assertStringContainsString('could not be sent', $html);
    }

    public function testAVerifiedStoreSignsTheOwnerIn(): void
    {
        $sent = null;
        ob_start();
        try {
            $this->createdController(
                false,
                '01900000-0000-7000-8000-0000000000bb',
                password_hash('password1', PASSWORD_DEFAULT),
            )->create();
            self::fail('a ready store stayed on the form');
        } catch (ResponseSent $error) {
            $sent = $error->getMessage();
        } finally {
            ob_end_clean();
        }

        self::assertSame('redirect', $sent);
    }

    private function createdController(bool $verify, mixed $ownerId, ?string $passwordHash = null): SignupController
    {
        $request = $this->request('POST', [
            'csrf' => 'csrf-token',
            'store_name' => 'Harbor',
            'email' => 'owner@harbor.test',
            'password' => 'password1',
            'password_confirm' => 'password1',
        ]);
        $answer = $this->createStub(PDOStatement::class);
        $answer->method('execute')->willReturn(true);
        $answer->method('rowCount')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($ownerId, $answer): PDOStatement {
            if (str_contains($sql, 'SELECT 1 FROM platform.tenants')) {
                return $this->valueStatement(false);
            }
            if (str_contains($sql, 'id::text FROM platform.tenants')) {
                return $this->valueStatement('01900000-0000-7000-8000-0000000000aa');
            }
            if (str_contains($sql, 'platform.owners')) {
                return $this->valueStatement($ownerId);
            }

            return $answer;
        });
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($answer);
        $signup = new StoreSignup(
            $pdo,
            $this->verificationSettings($verify),
            $this->limits(),
            new AdminAudit($pdo, $request),
            $this->createStub(LoggerInterface::class),
        );
        $signup->useAdmin(static fn (): PDO => $admin);
        $signup->useInstaller(static function (): void {
        });
        [$controller] = $this->controller(
            $request,
            $this->verificationSettings($verify),
            $this->confirmingVerification(),
            $signup,
            is_string($passwordHash) ? $this->signingPdo($passwordHash) : null,
        );

        return $controller;
    }

    private function verificationSettings(bool $verify): PlatformSettings
    {
        $key = '';
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function (array $params) use (&$key): bool {
            $key = is_string($params[0] ?? null) ? $params[0] : '';

            return true;
        });
        $statement->method('fetchColumn')->willReturnCallback(static function () use (&$key, $verify): string {
            if ($key === 'store_signup_require_email_verification') {
                return $verify ? 'true' : 'false';
            }

            return 'true';
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new PlatformSettings($pdo, $this->createStub(LoggerInterface::class));
    }

    private function valueStatement(mixed $value): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($value);
        $statement->method('rowCount')->willReturn(1);

        return $statement;
    }

    private function signingPdo(string $hash): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($hash): PDOStatement {
            if (str_contains($sql, 'schema_name')) {
                return $this->rowStatement([
                    'id' => '01900000-0000-7000-8000-0000000000aa',
                    'slug' => 'harbor',
                    'schema_name' => 'tenant_' . str_repeat('ab', 16),
                    'display_name' => 'Harbor',
                ]);
            }
            if (str_contains($sql, 'password_hash')) {
                return $this->rowStatement([
                    'id' => '01900000-0000-7000-8000-0000000000bb',
                    'password_hash' => $hash,
                    'credentials_version' => '1',
                    'email_verified_at' => '2026-11-12 00:00:00',
                    'can_sign_in' => true,
                ]);
            }

            return $this->valueStatement(0);
        });

        return $pdo;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowStatement(array $row): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn($row);
        $statement->method('fetchColumn')->willReturn(0);
        $statement->method('rowCount')->willReturn(1);

        return $statement;
    }

    private function restoreMailFile(mixed $previous): void
    {
        if (is_string($previous) && $previous !== '') {
            putenv('STALL_MAIL_FILE=' . $previous);

            return;
        }
        putenv('STALL_MAIL_FILE');
    }

    /**
     * @param array<string, mixed> $post
     */
    private function request(string $method, array $post): Request
    {
        return new Request($method, '/signup', [], $post, '', 'https://example.test', '203.0.113.10');
    }

    /**
     * @return array{0: SignupController, 1: ArrayObject<string, string>}
     */
    private function controller(
        Request $request,
        PlatformSettings $settings,
        ?OwnerEmailVerification $verification = null,
        ?StoreSignup $signup = null,
        ?PDO $appPdo = null,
    ): array {
        $bag = new ArrayObject(['csrf' => 'csrf-token']);
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use ($bag): mixed {
            return $bag->offsetExists($key) ? $bag->offsetGet($key) : null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($bag): void {
            $bag->offsetSet($key, is_string($value) ? $value : '');
        });
        $session->method('has')->willReturnCallback(static function (string $key) use ($bag): bool {
            return $bag->offsetExists($key);
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use ($bag): void {
            $bag->offsetUnset($key);
        });
        $pdo = $appPdo ?? $this->createStub(PDO::class);
        $logger = $this->createStub(LoggerInterface::class);
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
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
        $rows = $this->createStub(Statements::class);
        $mall = new MallSettings(
            $rows,
            new CompanyStore($rows, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($rows),
        );
        $app = new MallApp(
            $pdo,
            $session,
            $view,
            $request,
            new OwnerAccess(),
            $mall,
            new ActiveRegister($pdo, $session, $mall, new MallClock($mall)),
            $tenants,
            $door,
        );
        $controller = new SignupController(
            $app,
            $settings,
            $signup ?? new StoreSignup(
                $pdo,
                $settings,
                $this->limits(),
                new AdminAudit($pdo, $request),
                $logger,
            ),
            $verification ?? new OwnerEmailVerification($pdo, $this->mailbox(), $this->audit(), $logger),
            $owner,
        );

        return [$controller, $bag];
    }

    private function settings(bool $enabled): PlatformSettings
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($enabled ? 'true' : 'false');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new PlatformSettings($pdo, $this->createStub(LoggerInterface::class));
    }

    private function limits(): SignupRateLimit
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn(0);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new SignupRateLimit($pdo);
    }

    private function mailbox(): PlatformMailbox
    {
        $logger = $this->createStub(LoggerInterface::class);
        $notice = new PlatformOperatorNotice($logger);

        return new PlatformMailbox(new OutboundMail($logger), new GmailSender($notice), $notice);
    }

    private function audit(): AdminAudit
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new AdminAudit($pdo, new Request('GET', '/signup', [], []));
    }

    private function confirmingVerification(): OwnerEmailVerification
    {
        $row = [
            'id' => '01900000-0000-7000-8000-000000000010',
            'owner_id' => '01900000-0000-7000-8000-000000000011',
            'email' => 'owner@harbor.test',
            'slug' => 'cov-harbor',
            'tenant_id' => '01900000-0000-7000-8000-000000000012',
        ];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use ($row): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetch')->willReturn($row);
            $statement->method('rowCount')->willReturn(1);

            return $statement;
        });

        return new OwnerEmailVerification($pdo, $this->mailbox(), $this->audit(), $this->createStub(LoggerInterface::class));
    }

    /**
     * @param callable(): void $work
     */
    private function capture(callable $work): string
    {
        ob_start();
        try {
            $work();
        } finally {
            $html = ob_get_clean();
        }

        return is_string($html) ? $html : '';
    }
}
