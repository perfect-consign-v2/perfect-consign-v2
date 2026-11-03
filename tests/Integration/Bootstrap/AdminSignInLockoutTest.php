<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminSignInLockoutTest extends TestCase
{
    public function testFiveFailuresFromOneAddressLockThatPair(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'admin-lock-addr-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $insert = $pdo->prepare(
                    'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.8.0.1']);
            }
            $signIn = $this->signIn($pdo, '10.8.0.1');
            try {
                $signIn->attempt($email, 'wrong-password');
                self::fail('same-address failures did not lock the admin email');
            } catch (SignInLocked $locked) {
                self::assertStringContainsString('Too many sign-in attempts', $locked->getMessage());
            }
        } finally {
            $this->clear($pdo, $email);
        }
    }

    public function testTwentyFailuresAcrossAddressesLockTheEmail(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'admin-lock-email-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            for ($address = 1; $address <= 20; $address++) {
                $insert = $pdo->prepare(
                    'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.9.0.' . (string) $address]);
            }
            $signIn = $this->signIn($pdo, '10.9.0.99');
            try {
                $signIn->attempt($email, 'wrong-password');
                self::fail('distributed failures did not lock the admin email');
            } catch (SignInLocked $locked) {
                self::assertStringContainsString('Too many sign-in attempts', $locked->getMessage());
            }
        } finally {
            $this->clear($pdo, $email);
        }
    }

    public function testFortyFailuresFromOneAddressAcrossEmailsLockTheClient(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $emails = [];
        try {
            for ($n = 1; $n <= 40; $n++) {
                $email = 'admin-spray-' . bin2hex(random_bytes(3)) . $n . '@example.com';
                $emails[] = $email;
                $insert = $pdo->prepare(
                    'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.10.0.8']);
            }
            $next = 'admin-spray-next-' . bin2hex(random_bytes(4)) . '@example.com';
            $emails[] = $next;
            $signIn = $this->signIn($pdo, '10.10.0.8');
            try {
                $signIn->attempt($next, 'wrong-password');
                self::fail('address-wide spray failures did not lock the admin client');
            } catch (SignInLocked $locked) {
                self::assertStringContainsString('Too many sign-in attempts', $locked->getMessage());
            }
        } finally {
            foreach ($emails as $email) {
                $this->clear($pdo, $email);
            }
        }
    }

    public function testThirtyNineFailuresFromOneAddressAcrossEmailsStillAllowAnotherEmail(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $emails = [];
        try {
            for ($n = 1; $n <= 39; $n++) {
                $email = 'admin-under-' . bin2hex(random_bytes(3)) . $n . '@example.com';
                $emails[] = $email;
                $insert = $pdo->prepare(
                    'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.10.0.9']);
            }
            $next = 'admin-under-next-' . bin2hex(random_bytes(4)) . '@example.com';
            $emails[] = $next;
            $signIn = $this->signIn($pdo, '10.10.0.9');
            self::assertFalse($signIn->attempt($next, 'wrong-password'));
        } finally {
            foreach ($emails as $email) {
                $this->clear($pdo, $email);
            }
        }
    }

    private function clear(PDO $pdo, string $email): void
    {
        $clear = $pdo->prepare('DELETE FROM platform.admin_sign_in_failures WHERE email = ?');
        $clear->execute([$email]);
    }

    private function signIn(PDO $pdo, string $address): AdminSignIn
    {
        $request = new Request('POST', '/', [], [], '', '', $address);

        return new AdminSignIn(
            $pdo,
            $request,
            new NullLogger(),
            new AdminSession($this->session()),
            new AdminAudit($pdo, $request),
            new SignInFailureRetention($pdo),
        );
    }

    private function session(): Session
    {
        return new class () extends Session {
            /** @var array<string, mixed> */
            private array $bag = [];

            public function __construct()
            {
                parent::__construct(null, false);
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function regenerateId(bool $deleteOldSession = true): void
            {
            }

            public function destroy(): void
            {
                $this->bag = [];
            }
        };
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
