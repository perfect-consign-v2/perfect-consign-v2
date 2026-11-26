<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\AdminPasswordRecovery;
use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Feature\Admin\AdminResetMail;
use PerfectConsign\Feature\Admin\AdminResetTokens;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaFailures;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaLogin;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Admin\Mfa\RecoveryCode;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\QuietAudit;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminMfaLockoutTest extends TestCase
{
    public function testPairSprayAndEmailLocksSurviveANewSession(): void
    {
        $pdo = $this->pdo();
        $pairEmail = 'mfa-pair-' . bin2hex(random_bytes(4)) . '@example.test';
        $pairAddress = '198.51.100.' . (string) random_int(10, 200);
        $pairId = $this->admin($pdo, $pairEmail);
        $sprayAddress = 'mfa-spray-' . bin2hex(random_bytes(3));
        $emailLock = 'mfa-email-' . bin2hex(random_bytes(4)) . '@example.test';
        try {
            $first = $this->failures($pdo, $pairAddress);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $first->record($pairId, $pairEmail);
            }
            try {
                $this->failures($pdo, $pairAddress)->assertOpen($pairEmail);
                self::fail('a new session reset the authenticator pair lock');
            } catch (SignInLocked) {
                self::assertTrue(true);
            }
            $this->failures($pdo, 'mfa-other-' . bin2hex(random_bytes(3)))->assertOpen($pairEmail);

            for ($attempt = 0; $attempt < 40; $attempt++) {
                $this->insertFailure($pdo, 'mfa-spray-' . $attempt . '-' . bin2hex(random_bytes(2)) . '@example.test', $sprayAddress);
            }
            try {
                $this->failures($pdo, $sprayAddress)->assertOpen('mfa-spray-new-' . bin2hex(random_bytes(2)) . '@example.test');
                self::fail('one address was allowed to spray authenticator codes');
            } catch (SignInLocked) {
                self::assertTrue(true);
            }

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $this->insertFailure($pdo, $emailLock, 'mfa-addr-' . $attempt . '-' . bin2hex(random_bytes(2)));
            }
            try {
                $this->failures($pdo, 'mfa-addr-new-' . bin2hex(random_bytes(2)))->assertOpen($emailLock);
                self::fail('one email was allowed another authenticator guess');
            } catch (SignInLocked) {
                self::assertTrue(true);
            }
        } finally {
            $this->deleteFailures($pdo, $pairEmail);
            $this->deleteAddress($pdo, $sprayAddress);
            $this->deleteFailures($pdo, $emailLock);
        }
    }

    public function testPasswordSuccessDoesNotClearAuthenticatorFailures(): void
    {
        $pdo = $this->pdo();
        $email = 'mfa-keep-' . bin2hex(random_bytes(4)) . '@example.test';
        $password = 'mfa-keep-pass-1';
        $address = 'mfa-keep-' . bin2hex(random_bytes(3));
        $this->admin($pdo, $email, $password);
        AdminDoor::plant($pdo, $email);
        $this->insertFailure($pdo, $email, $address);
        $settings = new PlatformSettings($pdo, new NullLogger());
        $mfaWasOn = $settings->adminMfaEnabled();
        $settings->setAdminMfaEnabled(true);
        try {
            $session = $this->memorySession();
            $request = new Request('POST', '/login', [], [], '', '', $address);
            $audit = QuietAudit::admin($pdo, $request);
            $admins = new AdminSession($session);
            $store = new AdminMfaStore($pdo, $audit);
            $signIn = new AdminSignIn(
                $pdo,
                new NullLogger(),
                $audit,
                \PerfectConsign\Tests\Support\SignInTestKit::gate($pdo, $request),
                $store,
                new AdminMfaPending($session, $admins),
                new PlatformSettings($pdo, new NullLogger()),
                new AdminMfaLogin($store, $admins, $audit, new AdminMfaFailures(
                    $pdo,
                    $request,
                    new NullLogger(),
                    $audit,
                    new SignInFailureRetention($pdo),
                )),
            );
            $result = $signIn->attempt($email, $password);
            self::assertTrue($result->accepted());
            self::assertFalse($result->needsEnrollment());
            $count = $pdo->prepare('SELECT count(*) FROM platform.admin_mfa_failures WHERE email = ?');
            $count->execute([$email]);
            self::assertSame(1, (int) $count->fetchColumn());
        } finally {
            $settings->setAdminMfaEnabled($mfaWasOn);
            $this->deleteFailures($pdo, $email);
        }
    }

    public function testPasswordResetLeavesTheAuthenticatorAndItsFailures(): void
    {
        $pdo = $this->pdo();
        $email = 'mfa-reset-' . bin2hex(random_bytes(4)) . '@example.test';
        $id = $this->admin($pdo, $email, 'mfa-reset-pass-1');
        AdminDoor::plant($pdo, $email);
        $before = $this->secret($pdo, $email);
        $this->insertFailure($pdo, $email, 'mfa-reset-addr');
        $signInFailure = $pdo->prepare(
            "INSERT INTO platform.sign_in_attempts (door, account_key, email_tried, client_address, user_agent, outcome, trusted_device)
             VALUES ('admin', 'admin:' || ?, ?, 'mfa-reset-addr', '', 'wrong_password', false)"
        );
        $signInFailure->execute([$email, $email]);
        $token = (new AdminResetTokens($pdo))->issue($id);
        $this->recovery($pdo)->redeem($token, 'mfa-reset-pass-2', 'mfa-reset-pass-2');
        self::assertSame($before, $this->secret($pdo, $email));
        $mfa = $pdo->prepare('SELECT count(*) FROM platform.admin_mfa_failures WHERE email = ?');
        $mfa->execute([$email]);
        self::assertSame(1, (int) $mfa->fetchColumn());
        $latest = $pdo->prepare(
            "SELECT outcome FROM platform.sign_in_attempts WHERE account_key = 'admin:' || ? ORDER BY attempted_at DESC, id DESC LIMIT 1"
        );
        $latest->execute([$email]);
        self::assertSame('password_reset', $latest->fetchColumn());
        $this->deleteFailures($pdo, $email);
    }

    public function testClearAdminMfaBurnsCodesAndAuditsOnce(): void
    {
        $pdo = $this->pdo();
        $email = 'mfa-clear-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->admin($pdo, $email);
        AdminDoor::plant($pdo, $email);
        $code = AdminDoor::recoveryCode($pdo, $email);
        $hash = RecoveryCode::hash(RecoveryCode::normalize($code));
        $before = $this->clearedAudits($pdo, $email);
        $first = $this->runClear($email);
        self::assertSame(0, $first['code'], $first['stderr']);
        self::assertStringContainsString('platform admin authenticator cleared', $first['stdout']);
        self::assertNull($this->secretOrNull($pdo, $email));
        $used = $pdo->prepare(
            'SELECT used_at::text
             FROM platform.admin_mfa_recovery_codes
             WHERE code_hash = ?'
        );
        $used->execute([$hash]);
        $usedAt = $used->fetchColumn();
        self::assertIsString($usedAt);
        self::assertNotSame('', $usedAt);
        self::assertSame($before + 1, $this->clearedAudits($pdo, $email));
        $second = $this->runClear($email);
        self::assertSame(0, $second['code'], $second['stderr']);
        self::assertStringContainsString('platform admin authenticator already clear', $second['stdout']);
        self::assertSame($before + 1, $this->clearedAudits($pdo, $email));
    }

    private function failures(PDO $pdo, string $address): AdminMfaFailures
    {
        $request = new Request('POST', '/mfa', [], [], '', '', $address);

        return new AdminMfaFailures(
            $pdo,
            $request,
            new NullLogger(),
            QuietAudit::admin($pdo, $request),
            new SignInFailureRetention($pdo),
        );
    }

    private function admin(PDO $pdo, string $email, string $password = 'mfa-lock-pass-1'): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash
             RETURNING id::text'
        );
        $statement->execute([$email, $hash]);
        $id = $statement->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    private function insertFailure(PDO $pdo, string $email, string $address): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO platform.admin_mfa_failures (email, client_address) VALUES (?, ?)'
        );
        $statement->execute([$email, $address]);
    }

    private function deleteFailures(PDO $pdo, string $email): void
    {
        $statement = $pdo->prepare('DELETE FROM platform.admin_mfa_failures WHERE email = ?');
        $statement->execute([$email]);
    }

    private function deleteAddress(PDO $pdo, string $address): void
    {
        $statement = $pdo->prepare('DELETE FROM platform.admin_mfa_failures WHERE client_address = ?');
        $statement->execute([$address]);
    }

    private function secret(PDO $pdo, string $email): string
    {
        $secret = $this->secretOrNull($pdo, $email);
        self::assertIsString($secret);

        return $secret;
    }

    private function secretOrNull(PDO $pdo, string $email): ?string
    {
        $statement = $pdo->prepare(
            'SELECT mfa_secret FROM platform.admins WHERE lower(email) = lower(?)'
        );
        $statement->execute([$email]);
        $secret = $statement->fetchColumn();
        if ($secret === false || $secret === null) {
            return null;
        }
        self::assertIsString($secret);

        return $secret;
    }

    private function clearedAudits(PDO $pdo, string $email): int
    {
        $statement = $pdo->prepare(
            "SELECT count(*) FROM platform.admin_audit
             WHERE admin_email = ? AND action = 'admin.mfa_cleared'"
        );
        $statement->execute([$email]);

        return (int) $statement->fetchColumn();
    }

    private function recovery(PDO $pdo): AdminPasswordRecovery
    {
        $logger = new NullLogger();
        $session = new Session(null, false);
        $tokens = new AdminResetTokens($pdo);
        $request = new Request('POST', '/reset', [], [], '', 'http://127.0.0.1', '203.0.113.9');

        return new AdminPasswordRecovery(
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
                new AdminPath(AdminDoor::prefix()),
            ),
            QuietAudit::admin($pdo, $request),
            new AdminSession($session),
            new LoginSession($session),
            new \PerfectConsign\Feature\Admin\AdminPasswordSave($pdo, \PerfectConsign\Tests\Support\SignInTestKit::gate($pdo, $request)),
        );
    }

    private function memorySession(): Session
    {
        return new class () extends Session {
            /** @var array<string, mixed> */
            private array $bag = [];

            public function __construct()
            {
            }

            public function regenerateId(bool $deleteOldSession = true): void
            {
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function delete(string $key): void
            {
                unset($this->bag[$key]);
            }
        };
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runClear(string $email): array
    {
        $root = dirname(__DIR__, 3);
        $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'clear-admin-mfa.php';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, $email],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        return [
            'code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function pdo(): PDO
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $user = getenv('STALL_DB_USER');
        $password = getenv('STALL_DB_PASSWORD');
        $host = is_string($host) && $host !== '' ? $host : '127.0.0.1';
        $port = is_string($port) && $port !== '' ? $port : '5436';
        $name = is_string($name) && $name !== '' ? $name : 'consign';
        $user = is_string($user) && $user !== '' ? $user : 'stall';
        $password = is_string($password) && $password !== '' ? $password : 'stall';

        return new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}
