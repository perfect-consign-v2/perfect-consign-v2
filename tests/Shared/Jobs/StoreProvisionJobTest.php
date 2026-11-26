<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\PlatformJobs;
use PerfectConsign\Shared\Jobs\StoreProvisionJob;
use PerfectConsign\Shared\Mail\MailOutbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store-build job's refusals. A real build as stall_provisioner is in
 * tests/Integration/Feature/StoreSignupFlowTest.
 */
final class StoreProvisionJobTest extends TestCase
{
    private const SIGNUP = '01900000-0000-7000-8000-0000000000cc';

    /** @var list<string> */
    private array $ran = [];

    public function testASignupThatIsGoneOrFinishedIsLeftAlone(): void
    {
        $this->refused('the signup is missing', fn () => $this->job($this->database(['row' => false]))->build(self::SIGNUP));
        $this->refused('the signup is missing', fn () => $this->job($this->database(['row' => array_merge($this->row('verified'), ['slug' => null])]))->build(self::SIGNUP));
        foreach (['pending', 'expired', 'discarded'] as $status) {
            $this->ran = [];
            $this->job($this->database(['row' => $this->row($status)]))->build(self::SIGNUP);
            self::assertSame([], $this->updates());
        }
        $this->ran = [];
        $this->job($this->database(['row' => $this->row('ready', 'harbor', '01900000-0000-7000-8000-0000000000dd')]))->build(self::SIGNUP);
        self::assertSame([], $this->updates());
    }

    public function testABuildThatCannotStartIsMarkedFailed(): void
    {
        $this->refused(
            'the worker could not become the store builder',
            fn () => $this->job($this->database(['row' => $this->row('verified'), 'role' => false]))->build(self::SIGNUP)
        );
        self::assertSame(['provisioning', 'failed'], $this->updates());
        self::assertNotContains('RESET ROLE', $this->ran);

        $this->ran = [];
        $this->refused(
            'the signup is not ready to build',
            fn () => $this->job($this->database(['row' => $this->row('provisioning'), 'build' => false]))->build(self::SIGNUP)
        );
        self::assertSame(['failed'], $this->updates());

        $this->ran = [];
        $this->refused(
            'no free store address was found',
            fn () => $this->job($this->database(['row' => $this->row('failed'), 'taken' => true]))->build(self::SIGNUP)
        );
        self::assertSame(['provisioning', 'failed'], $this->updates());
        self::assertContains('RESET search_path', $this->ran);
    }

    public function testTheReadyLetterNeedsAnOriginAndRollsBackWhenItCannotBeFiled(): void
    {
        $ready = $this->row('ready', 'harbor-2');
        $this->refused(
            'STALL_PUBLIC_ORIGIN is required for the store-ready letter',
            fn () => $this->job($this->database(['row' => $ready]), 'example.test')->build(self::SIGNUP)
        );
        $rolledBack = false;
        $pdo = $this->database(['row' => $ready, 'mail' => 'not-an-id'], $rolledBack);
        $this->refused('the mail could not be filed', fn () => $this->job($pdo)->build(self::SIGNUP));
        self::assertTrue($rolledBack);
    }

    public function testTheCleanUpAsksTheDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('query')->with('SELECT platform.signup_reap()');
        (new PlatformJobs($pdo, $this->job($pdo)))->reapSignups();
    }

    public function testTheSignInCleanUpAsksTheDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('query')->with('SELECT platform.sign_in_reap()');
        (new PlatformJobs($pdo, $this->job($pdo)))->reapSignIns();
    }

    /**
     * @return array<string, string|null>
     */
    private function row(string $status, string $slug = '', string $mail = ''): array
    {
        return [
            'id' => self::SIGNUP,
            'email' => 'owner@harbor.test',
            'store_name' => 'Harbor',
            'status' => $status,
            'slug' => $slug,
            'ready_mail_id' => $mail,
        ];
    }

    /**
     * @return list<string>
     */
    private function updates(): array
    {
        $statuses = [];
        foreach ($this->ran as $sql) {
            if (preg_match("/SET status = '([a-z]+)'/", $sql, $match) === 1 && !str_contains($sql, "'ready'")) {
                $statuses[] = $match[1];
            }
        }

        return $statuses;
    }

    /**
     * @param array{row?: array<string, string|null>|false, role?: bool, build?: bool, taken?: bool, mail?: string} $script
     */
    private function database(array $script, bool &$rolledBack = false): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use ($script): int|false {
            $this->ran[] = $sql;

            return $sql === 'SET ROLE stall_provisioner' && ($script['role'] ?? true) === false ? false : 0;
        });
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$rolledBack): bool {
            $rolledBack = true;

            return true;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($script): PDOStatement {
            $this->ran[] = $sql;
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetch')->willReturn(match (true) {
                str_contains($sql, 'password_hash FROM') => ($script['build'] ?? true)
                    ? ['email' => 'owner@harbor.test', 'store_name' => 'Harbor', 'password_hash' => 'hash']
                    : false,
                default => $script['row'] ?? false,
            });
            $statement->method('fetchColumn')->willReturn(match (true) {
                str_contains($sql, 'FROM platform.tenants') => ($script['taken'] ?? false) ? 1 : false,
                str_contains($sql, 'file_outbound_mail') => $script['mail'] ?? '01900000-0000-7000-8000-0000000000dd',
                default => false,
            });

            return $statement;
        });

        return $pdo;
    }

    private function job(PDO $pdo, string $origin = 'https://example.test'): StoreProvisionJob
    {
        return new StoreProvisionJob($pdo, new MailOutbox($pdo), dirname(__DIR__, 3) . '/sql', $origin);
    }

    private function refused(string $message, callable $work): void
    {
        try {
            $work();
            self::fail('expected: ' . $message);
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}
