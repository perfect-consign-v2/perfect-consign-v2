<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\EmailSignIn;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\SignInAccount;
use PerfectConsign\Bootstrap\SignInAlert;
use PerfectConsign\Bootstrap\SignInCheck;
use PerfectConsign\Bootstrap\SignInDirectory;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaFailures;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaLogin;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestTrace;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PerfectConsign\Tests\Support\SignInTestKit;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * The sign-in limits against the real database: the pair, address and
 * account-wide limits at both doors, the quiet refusal of an email that signs
 * in nowhere, the trusted device, the alert email, the password reset, and a
 * parallel burst that cannot slip past the pair limit.
 */
final class SignInLimitsTest extends TestCase
{
    private PDO $pdo;

    private string $net;

    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $this->pdo = $this->pdo();
        $this->net = '10.' . (string) random_int(100, 250) . '.' . (string) random_int(0, 255) . '.';
    }

    protected function tearDown(): void
    {
        $attempts = $this->pdo->prepare('DELETE FROM platform.sign_in_attempts WHERE account_key = ? OR client_address LIKE ?');
        $devices = $this->pdo->prepare('DELETE FROM platform.sign_in_devices WHERE account_key = ?');
        foreach (array_unique($this->keys) as $key) {
            $attempts->execute([$key, $this->net . '%']);
            $devices->execute([$key]);
        }
        $attempts->execute(['', $this->net . '%']);
        $this->pdo->exec(
            "DELETE FROM platform.jobs WHERE kind = 'outbound-mail'
             AND payload ->> 'mail' IN (SELECT id::text FROM platform.mail_outbox WHERE purpose = 'sign-in-alert')"
        );
        $this->pdo->exec("DELETE FROM platform.mail_outbox WHERE purpose = 'sign-in-alert'");
    }

    public function testFiveFailuresFromOneAddressLockThatPairOnly(): void
    {
        $email = $this->unknown('pair');
        $this->seed('email:' . $email, 5, fn (int $n): string => $this->net . '1');
        $this->locked(fn () => $this->emailSignIn($this->net . '1')->attempt($email, 'wrong-password'));
        self::assertFalse($this->emailSignIn($this->net . '2')->attempt($email, 'wrong-password'));
        self::assertSame(['locked_pair' => 1, 'unknown_email' => 6], $this->outcomes('email:' . $email));
    }

    public function testTwentyFailuresAcrossAddressesLockAnUnknownEmailQuietly(): void
    {
        $email = $this->unknown('spread');
        $this->seed('email:' . $email, 19, fn (int $n): string => $this->net . (string) $n);
        self::assertFalse($this->emailSignIn($this->net . '99')->attempt($email, 'wrong-password'));
        $this->locked(fn () => $this->emailSignIn($this->net . '98')->attempt($email, 'wrong-password'));
        self::assertSame(0, $this->alerts());
    }

    public function testFortyFailuresFromOneAddressLockThatAddressForEveryEmail(): void
    {
        for ($n = 1; $n <= 39; $n++) {
            $this->seed('email:' . $this->unknown('spray' . $n), 1, fn (int $i): string => $this->net . '7');
        }
        self::assertFalse($this->emailSignIn($this->net . '7')->attempt($this->unknown('next'), 'wrong-password'));
        $this->locked(fn () => $this->emailSignIn($this->net . '7')->attempt($this->unknown('last'), 'wrong-password'));
    }

    public function testALockedStoreAccountAlertsOnceAndATrustedBrowserStillGetsIn(): void
    {
        $tenant = $this->probeTenant();
        $account = SignInAccount::store($tenant, ProbeMall::USERNAME);
        $key = 'store:' . $tenant . ':' . ProbeMall::USERNAME;
        $this->keys[] = $key;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $device = $this->pdo->prepare(
            'INSERT INTO platform.sign_in_devices (account_key, tenant_id, username, token_hash, label) VALUES (?, ?::uuid, ?, ?, ?)'
        );
        $device->execute([$key, $tenant, ProbeMall::USERNAME, hash('sha256', $token), 'Shop PC']);
        $this->seed($key, 20, fn (int $n): string => $this->net . (string) $n);

        $stranger = SignInTestKit::gate($this->pdo, $this->request($this->net . '200'));
        $this->locked(fn () => $stranger->attempt($account, 'guess', static fn (): SignInCheck => SignInCheck::Wrong));
        $this->locked(fn () => $stranger->attempt($account, 'guess', static fn (): SignInCheck => SignInCheck::Wrong));
        $owner = $this->pdo->prepare("SELECT count(*) FROM platform.mail_outbox WHERE purpose = 'sign-in-alert' AND address = ? AND subject = ?");
        $owner->execute([ProbeMall::EMAIL, SignInAlert::OWNER_SUBJECT]);
        self::assertSame(1, (int) $owner->fetchColumn());

        $shop = SignInTestKit::gate($this->pdo, $this->request($this->net . '201'), ['pc_device' => $token]);
        $checked = false;
        self::assertTrue($shop->attempt($account, ProbeMall::PASSWORD, static function () use (&$checked): SignInCheck {
            $checked = true;

            return SignInCheck::Right;
        }));
        self::assertTrue($checked);
        $trusted = $this->pdo->prepare(
            "SELECT trusted_device FROM platform.sign_in_attempts WHERE account_key = ? AND outcome = 'ok' ORDER BY attempted_at DESC LIMIT 1"
        );
        $trusted->execute([$key]);
        self::assertTrue((bool) $trusted->fetchColumn());
        $seen = $this->pdo->prepare('SELECT count(*) FROM platform.sign_in_devices WHERE account_key = ? AND token_hash = ?');
        $seen->execute([$key, hash('sha256', $token)]);
        self::assertSame(1, (int) $seen->fetchColumn());
    }

    public function testARightPasswordRemembersTheBrowserAndAResetEndsTheCount(): void
    {
        $email = 'limits-admin-' . bin2hex(random_bytes(4)) . '@example.test';
        $key = 'admin:' . $email;
        $this->keys[] = $key;
        $this->seed($key, 5, fn (int $n): string => $this->net . '3');
        $gate = SignInTestKit::gate($this->pdo, $this->request($this->net . '3'));
        $this->locked(fn () => $gate->attempt(SignInAccount::admin($email), 'x', static fn (): SignInCheck => SignInCheck::Wrong));
        $gate->reset(SignInAccount::admin($email));
        self::assertTrue($gate->attempt(SignInAccount::admin($email), 'x', static fn (): SignInCheck => SignInCheck::Right));
        $devices = $this->pdo->prepare('SELECT label FROM platform.sign_in_devices WHERE account_key = ?');
        $devices->execute([$key]);
        self::assertSame('limits test', $devices->fetchColumn());
    }

    public function testTheAdminDoorAuditsAKnownAdminAndCountsAnUnknownOne(): void
    {
        $unknown = 'limits-nobody-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->keys[] = 'admin:' . $unknown;
        $before = $this->refusals($unknown);
        self::assertFalse($this->adminSignIn($this->net . '4')->attempt($unknown, 'wrong-password')->accepted());
        self::assertSame($before, $this->refusals($unknown));
        self::assertSame(['unknown_email' => 1], $this->outcomes('admin:' . $unknown));

        $known = 'limits-known-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->keys[] = 'admin:' . $known;
        $insert = $this->pdo->prepare('INSERT INTO platform.admins (id, email, password_hash) VALUES (uuidv7(), ?, ?)');
        $insert->execute([$known, password_hash('correct-horse', PASSWORD_DEFAULT)]);
        self::assertFalse($this->adminSignIn($this->net . '5')->attempt($known, 'wrong-password')->accepted());
        self::assertSame(1, $this->refusals($known));
        self::assertSame(['wrong_password' => 1], $this->outcomes('admin:' . $known));
    }

    public function testTwentyParallelWrongPasswordsCountExactlyThePairLimit(): void
    {
        $tenant = $this->probeTenant();
        $username = 'race' . bin2hex(random_bytes(3));
        $key = 'store:' . $tenant . ':' . $username;
        $this->keys[] = $key;
        $start = microtime(true) + 2.0;
        $child = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'sign-in-race-child.php';
        $processes = [];
        for ($n = 0; $n < 20; $n++) {
            $process = proc_open(
                [PHP_BINARY, $child, $tenant, $username, $this->net . '9', sprintf('%.6F', $start)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $outputs[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        sort($outputs);
        self::assertSame(array_merge(array_fill(0, 15, 'locked'), array_fill(0, 5, 'wrong')), $outputs);
        self::assertSame(['locked_pair' => 15, 'wrong_password' => 5], $this->outcomes($key));
    }

    public function testAuthenticatorFailuresStillPruneAndTheIndexesExist(): void
    {
        $email = 'stale-mfa-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            $stale = $this->pdo->prepare(
                "INSERT INTO platform.admin_mfa_failures (email, client_address, failed_at)
                 VALUES (?, '10.23.0.1', clock_timestamp() - interval '20 minutes')"
            );
            $stale->execute([$email]);
            (new SignInFailureRetention($this->pdo))->pruneAdminMfa($email);
            $count = $this->pdo->prepare('SELECT count(*) FROM platform.admin_mfa_failures WHERE email = ?');
            $count->execute([$email]);
            self::assertSame(0, (int) $count->fetchColumn());
        } finally {
            $this->pdo->prepare('DELETE FROM platform.admin_mfa_failures WHERE email = ?')->execute([$email]);
        }
        foreach (['admin_mfa_failures_stale', 'sign_in_attempts_account', 'sign_in_attempts_address', 'sign_in_attempts_time', 'sign_in_devices_seen'] as $index) {
            $check = $this->pdo->prepare("SELECT 1 FROM pg_indexes WHERE schemaname = 'platform' AND indexname = ?");
            $check->execute([$index]);
            self::assertSame(1, $check->rowCount(), $index . ' is missing');
        }
    }

    /**
     * @param callable(int): string $address
     */
    private function seed(string $key, int $count, callable $address): void
    {
        $this->keys[] = $key;
        $email = str_starts_with($key, 'store:') ? null : substr($key, (int) strpos($key, ':') + 1);
        $insert = $this->pdo->prepare(
            "INSERT INTO platform.sign_in_attempts (door, account_key, email_tried, client_address, user_agent, outcome, trusted_device)
             VALUES (?, ?, ?, ?, '', ?, false)"
        );
        for ($n = 1; $n <= $count; $n++) {
            $insert->execute([
                str_starts_with($key, 'admin:') ? 'admin' : 'store',
                $key,
                $email,
                $address($n),
                str_starts_with($key, 'email:') ? 'unknown_email' : 'wrong_password',
            ]);
        }
    }

    private function locked(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('the attempt was not refused');
        } catch (SignInLocked $locked) {
            self::assertSame('Too many sign-in attempts. Try again in a few minutes.', $locked->getMessage());
        }
    }

    /**
     * @return array<string, int>
     */
    private function outcomes(string $key): array
    {
        $rows = $this->pdo->prepare(
            'SELECT outcome, count(*) AS n FROM platform.sign_in_attempts WHERE account_key = ? GROUP BY outcome ORDER BY outcome'
        );
        $rows->execute([$key]);
        $out = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['outcome']] = (int) $row['n'];
        }

        return $out;
    }

    private function alerts(): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM platform.mail_outbox WHERE purpose = 'sign-in-alert'")?->fetchColumn();
    }

    private function refusals(string $email): int
    {
        $count = $this->pdo->prepare(
            "SELECT count(*) FROM platform.admin_audit WHERE admin_email = ? AND action = 'admin.sign_in_refused'"
        );
        $count->execute([$email]);

        return (int) $count->fetchColumn();
    }

    private function unknown(string $label): string
    {
        $email = 'limits-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->keys[] = 'email:' . $email;

        return $email;
    }

    private function probeTenant(): string
    {
        $row = $this->pdo->prepare('SELECT id::text FROM platform.tenants WHERE slug = ?');
        $row->execute([ProbeMall::SLUG]);
        $id = $row->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    private function request(string $address): Request
    {
        return new Request('POST', '/', [], [], '', '', $address, [], new RequestTrace(userAgent: 'limits test'));
    }

    private function emailSignIn(string $address): EmailSignIn
    {
        $door = (new ReflectionClass(FrontDoor::class))->newInstanceWithoutConstructor();

        return new EmailSignIn(
            new NullLogger(),
            new SignInDirectory($this->pdo),
            $door,
            SignInTestKit::gate($this->pdo, $this->request($address)),
        );
    }

    private function adminSignIn(string $address): AdminSignIn
    {
        $session = (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        $admins = new AdminSession($session);
        $request = $this->request($address);
        $audit = QuietAudit::admin($this->pdo, $request);
        $store = new AdminMfaStore($this->pdo, $audit);

        return new AdminSignIn(
            $this->pdo,
            new NullLogger(),
            $audit,
            SignInTestKit::gate($this->pdo, $request),
            $store,
            new AdminMfaPending($session, $admins),
            new PlatformSettings($this->pdo, new NullLogger()),
            new AdminMfaLogin($store, $admins, $audit, new AdminMfaFailures(
                $this->pdo,
                $request,
                new NullLogger(),
                $audit,
                new SignInFailureRetention($this->pdo),
            )),
        );
    }

    private function pdo(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is not set');
        }

        return $value;
    }
}
