<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PerfectConsign\Bootstrap\EmailSignIn;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\SignInDirectory;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

final class EmailSignInLockoutTest extends TestCase
{
    public function testFiveFailuresFromOneAddressLockThatPair(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'lock-addr-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $insert = $pdo->prepare(
                    'INSERT INTO platform.email_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.8.0.1']);
            }
            $signIn = $this->signIn($pdo, '10.8.0.1');
            try {
                $signIn->attempt($email, 'wrong-password');
                self::fail('same-address failures did not lock the email');
            } catch (SignInLocked $locked) {
                self::assertStringContainsString('Too many sign-in attempts', $locked->getMessage());
            }
        } finally {
            $this->clear($pdo, $email);
        }
    }

    public function testFiveFailuresAcrossAddressesDoNotLockTheEmail(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'lock-spread-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            for ($address = 1; $address <= 5; $address++) {
                $insert = $pdo->prepare(
                    'INSERT INTO platform.email_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.8.0.' . (string) $address]);
            }
            $signIn = $this->signIn($pdo, '10.8.0.99');
            self::assertFalse($signIn->attempt($email, 'wrong-password'));
        } finally {
            $this->clear($pdo, $email);
        }
    }

    public function testTwentyFailuresAcrossAddressesLockTheEmail(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'lock-email-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            for ($address = 1; $address <= 20; $address++) {
                $insert = $pdo->prepare(
                    'INSERT INTO platform.email_sign_in_failures (email, client_address) VALUES (?, ?)'
                );
                $insert->execute([$email, '10.9.0.' . (string) $address]);
            }
            $signIn = $this->signIn($pdo, '10.9.0.99');
            try {
                $signIn->attempt($email, 'wrong-password');
                self::fail('distributed failures did not lock the email');
            } catch (SignInLocked $locked) {
                self::assertStringContainsString('Too many sign-in attempts', $locked->getMessage());
            }
        } finally {
            $this->clear($pdo, $email);
        }
    }

    public function testRecordingAFailurePrunesStaleRows(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'prune-' . bin2hex(random_bytes(4)) . '@example.com';
        $stale = 'stale-' . bin2hex(random_bytes(4)) . '@example.com';
        try {
            $old = $pdo->prepare(
                "INSERT INTO platform.email_sign_in_failures (email, client_address, failed_at)
                 VALUES (?, '127.0.0.1', clock_timestamp() - interval '20 minutes')"
            );
            $old->execute([$stale]);
            $signIn = $this->signIn($pdo, '127.0.0.1');
            self::assertFalse($signIn->attempt($email, 'wrong-password'));
            $left = $pdo->prepare('SELECT count(*) FROM platform.email_sign_in_failures WHERE email = ?');
            $left->execute([$stale]);
            self::assertSame(0, (int) $left->fetchColumn());
            $left->execute([$email]);
            self::assertSame(1, (int) $left->fetchColumn());
        } finally {
            $clear = $pdo->prepare('DELETE FROM platform.email_sign_in_failures WHERE email IN (?, ?)');
            $clear->execute([$email, $stale]);
        }
    }

    private function clear(PDO $pdo, string $email): void
    {
        $clear = $pdo->prepare('DELETE FROM platform.email_sign_in_failures WHERE email = ?');
        $clear->execute([$email]);
    }

    private function signIn(PDO $pdo, string $address): EmailSignIn
    {
        $door = (new ReflectionClass(FrontDoor::class))->newInstanceWithoutConstructor();

        return new EmailSignIn(
            $pdo,
            new Request('POST', '/', [], [], '', '', $address),
            new NullLogger(),
            new SignInDirectory($pdo),
            $door,
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
