<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminPasswordRecovery;
use PerfectConsign\Feature\Admin\AdminResetMail;
use PerfectConsign\Feature\Admin\AdminResetTokens;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class AdminPasswordRecoveryTest extends TestCase
{
    private const ADMIN_ID = '01900000-0000-7000-8000-0000000000aa';

    private const RESET_ID = '01900000-0000-7000-8000-0000000000bb';

    private const EMAIL = 'admin@example.test';

    private string|false|null $savedMailFile = null;

    protected function tearDown(): void
    {
        if ($this->savedMailFile === null) {
            return;
        }
        if (!is_string($this->savedMailFile) || $this->savedMailFile === '') {
            putenv('STALL_MAIL_FILE');
        } else {
            putenv('STALL_MAIL_FILE=' . $this->savedMailFile);
        }
        $this->savedMailFile = null;
    }

    public function testABadAddressNeverAsksTheDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('beginTransaction');
        $recovery = $this->recovery($pdo, '203.0.113.10');

        $recovery->request('not-an-email', 'http://127.0.0.1');
        $recovery->request(str_repeat('a', 196) . '@b.co', 'http://127.0.0.1');

        self::assertFalse($recovery->available('short'));
    }

    public function testFiveResetsForOneEmailStopTheRequest(): void
    {
        $commits = 0;
        $rollbacks = 0;
        $pdo = $this->pdo($this->counts(5, 0), null, $commits, $rollbacks);

        $this->recovery($pdo, '203.0.113.11')->request(self::EMAIL, 'http://127.0.0.1');

        self::assertSame(1, $commits);
        self::assertSame(0, $rollbacks);
    }

    public function testFortyResetsFromOneAddressStopTheRequest(): void
    {
        $commits = 0;
        $pdo = $this->pdo($this->counts(4, 40), null, $commits);

        $this->recovery($pdo, '203.0.113.12')->request(self::EMAIL, 'http://127.0.0.1');

        self::assertSame(1, $commits);
    }

    public function testAnUnknownAdminStoresTheAttemptAndSendsNothing(): void
    {
        $sawInsert = false;
        $pdo = $this->pdo(function (string $sql) use (&$sawInsert): PDOStatement {
            if (str_contains($sql, 'INSERT INTO platform.admin_password_reset_requests')) {
                $sawInsert = true;
            }
            if (str_contains($sql, 'lower(email)')) {
                return $this->statement(false, false);
            }

            return $this->countStatement($sql, 0, 0);
        });

        $this->recovery($pdo, '203.0.113.13')->request(self::EMAIL, 'http://127.0.0.1');

        self::assertTrue($sawInsert);
    }

    public function testABrokenAdminRowIsIgnored(): void
    {
        $rows = [
            false,
            ['id' => 12, 'email' => self::EMAIL, 'password_hash' => 'hash'],
            ['id' => self::ADMIN_ID, 'email' => null, 'password_hash' => 'hash'],
            ['id' => self::ADMIN_ID, 'email' => self::EMAIL, 'password_hash' => null],
        ];
        $pdo = $this->pdo(function (string $sql) use (&$rows): PDOStatement {
            if (str_contains($sql, 'lower(email)')) {
                $row = array_shift($rows);

                return $this->statement(false, $row ?? false);
            }

            return $this->countStatement($sql, 0, 0);
        });
        $recovery = $this->recovery($pdo, '203.0.113.14');
        $recovery->request(self::EMAIL, 'http://127.0.0.1');
        $recovery->request(self::EMAIL, 'http://127.0.0.1');
        $recovery->request(self::EMAIL, 'http://127.0.0.1');
        $recovery->request(self::EMAIL, 'http://127.0.0.1');

        self::assertSame([], $rows);
    }

    public function testAKnownAdminIsMailedAndAudited(): void
    {
        $this->useMailFile();
        $pdo = $this->pdo(function (string $sql): PDOStatement {
            if (str_contains($sql, 'lower(email)')) {
                return $this->statement(false, $this->adminRow());
            }
            if (str_contains($sql, 'admin_audit')) {
                return $this->statement(false, false, 1);
            }
            if (str_contains($sql, 'INSERT INTO platform.admin_password_resets')) {
                return $this->statement();
            }
            if (str_contains($sql, 'admin_password_resets')) {
                return $this->statement();
            }

            return $this->countStatement($sql, 0, 0);
        });

        $this->recovery($pdo, '203.0.113.15')->request(self::EMAIL, 'http://127.0.0.1:8097');

        $mail = getenv('STALL_MAIL_FILE');
        self::assertIsString($mail);
        $body = file_get_contents($mail);
        self::assertIsString($body);
        self::assertStringContainsString('/admin/reset/', $body);
    }

    public function testAFailedResetClaimRollsBack(): void
    {
        $commits = 0;
        $rollbacks = 0;
        $pdo = $this->pdo(static function (): PDOStatement {
            throw new RuntimeException('lock failed');
        }, null, $commits, $rollbacks);

        try {
            $this->recovery($pdo, '203.0.113.16')->request(self::EMAIL, 'http://127.0.0.1');
            self::fail('the failed claim was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('lock failed', $error->getMessage());
        }
        self::assertSame(1, $rollbacks);
    }

    public function testAFailedResetClaimSkipsRollbackWhenTheTransactionIsGone(): void
    {
        $commits = 0;
        $rollbacks = 0;
        $pdo = $this->pdo(static function (): PDOStatement {
            throw new RuntimeException('lock failed');
        }, false, $commits, $rollbacks);

        try {
            $this->recovery($pdo, '203.0.113.17')->request(self::EMAIL, 'http://127.0.0.1');
            self::fail('the failed claim was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('lock failed', $error->getMessage());
        }
        self::assertSame(0, $rollbacks);
    }

    public function testRedeemRejectsAMissingTokenAndRollsBack(): void
    {
        $commits = 0;
        $rollbacks = 0;
        $pdo = $this->pdo(fn (string $sql): PDOStatement => $this->statement(), null, $commits, $rollbacks);

        try {
            $this->recovery($pdo, '203.0.113.18')->redeem(str_repeat('b', 43), 'new-pass-1', 'new-pass-1');
            self::fail('a missing reset was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That reset link expired. Request a new one.', $error->getMessage());
        }
        self::assertSame(1, $rollbacks);
    }

    public function testRedeemSkipsRollbackWhenTheTransactionIsAlreadyClosed(): void
    {
        $commits = 0;
        $rollbacks = 0;
        $pdo = $this->pdo(fn (): PDOStatement => $this->statement(), false, $commits, $rollbacks);

        try {
            $this->recovery($pdo, '203.0.113.19')->redeem('short', 'new-pass-1', 'new-pass-1');
            self::fail('a closed reset was accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $rollbacks);
        }
    }

    public function testRedeemRejectsAMissingAdmin(): void
    {
        $pdo = $this->pdo(function (string $sql): PDOStatement {
            if (str_contains($sql, 'FOR UPDATE')) {
                return $this->statement(false, [
                    'id' => self::RESET_ID,
                    'account_id' => self::ADMIN_ID,
                ]);
            }

            return $this->statement(false, false);
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->recovery($pdo, '203.0.113.20')->redeem(str_repeat('c', 43), 'new-pass-1', 'new-pass-1');
    }

    public function testRedeemRefusesAVersionThatIsNotText(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the platform admin password could not be saved');
        $this->redeemVersion(false);
    }

    public function testRedeemRefusesABlankVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the platform admin password could not be saved');
        $this->redeemVersion('');
    }

    public function testRedeemSavesAnIntegerVersionAndClearsTheSession(): void
    {
        $session = new Session([
            'owner_slug' => 'webprobe',
            'platform_admin_id' => self::ADMIN_ID,
        ], false);
        $this->redeemVersion(4, $session);

        self::assertNull($session->get('owner_slug'));
        self::assertNull($session->get('platform_admin_id'));
    }

    public function testRedeemRejectsATokenThatWasNotConsumed(): void
    {
        $pdo = $this->redeemPdo('6', 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That reset link expired. Request a new one.');
        $this->recovery($pdo, '203.0.113.24')->redeem(str_repeat('d', 43), 'new-pass-1', 'new-pass-1');
    }

    public function testChangeRejectsAnUnknownAdmin(): void
    {
        $pdo = $this->pdo(fn (): PDOStatement => $this->statement(false, 'nope'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That password is wrong.');
        $this->recovery($pdo, '203.0.113.25')->change(self::ADMIN_ID, 'old-pass-1', 'new-pass-1', 'new-pass-1');
    }

    public function testChangeRefusesAVersionThatIsNotText(): void
    {
        $this->expectException(RuntimeException::class);
        $this->changeVersion(false);
    }

    public function testChangeRefusesABlankVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->changeVersion('');
    }

    public function testChangeStoresAnIntegerVersion(): void
    {
        self::assertSame('9', $this->changeVersion(9));
    }

    public function testChangeStoresAStringVersionAndStampsTheSession(): void
    {
        $session = new Session([
            AdminSession::ID => self::ADMIN_ID,
            AdminSession::EMAIL => self::EMAIL,
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => (string) time(),
            'platform_last_seen_at' => (string) time(),
        ], false);

        self::assertSame('3', $this->changeVersion('3', $session));
        self::assertSame('3', $session->get(AdminSession::VERSION));
    }

    private function redeemVersion(mixed $version, ?Session $session = null): void
    {
        $this->recovery($this->redeemPdo($version, 1), '203.0.113.21', $session)
            ->redeem(str_repeat('d', 43), 'new-pass-1', 'new-pass-1');
    }

    private function changeVersion(mixed $version, ?Session $session = null): string
    {
        return $this->recovery($this->changePdo($version), '203.0.113.26', $session)
            ->change(self::ADMIN_ID, 'old-pass-1', 'new-pass-1', 'new-pass-1');
    }

    private function redeemPdo(mixed $version, int $consumed): PDO
    {
        return $this->pdo(function (string $sql) use ($version, $consumed): PDOStatement {
            if (str_contains($sql, 'FOR UPDATE')) {
                return $this->statement(false, [
                    'id' => self::RESET_ID,
                    'account_id' => self::ADMIN_ID,
                ]);
            }
            if (str_contains($sql, 'FROM platform.admins')) {
                return $this->statement(false, $this->adminRow());
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
        return $this->pdo(function (string $sql) use ($version): PDOStatement {
            if (str_contains($sql, 'FROM platform.admins')) {
                return $this->statement(false, $this->adminRow());
            }
            if (str_contains($sql, 'UPDATE platform.admins')) {
                return $this->statement($version);
            }

            return $this->statement(false, false, 1);
        });
    }

    /**
     * @return array{id: string, email: string, password_hash: string}
     */
    private function adminRow(): array
    {
        return [
            'id' => self::ADMIN_ID,
            'email' => self::EMAIL,
            'password_hash' => (new PasswordChoice())->hash('old-pass-1'),
        ];
    }

    /**
     * @return callable(string): PDOStatement
     */
    private function counts(int $recent, int $spray): callable
    {
        return function (string $sql) use ($recent, $spray): PDOStatement {
            return $this->countStatement($sql, $recent, $spray);
        };
    }

    private function countStatement(string $sql, int $recent, int $spray): PDOStatement
    {
        if (str_contains($sql, 'client_address')) {
            return $this->statement($spray);
        }
        if (str_contains($sql, 'admin_password_reset_requests') && str_contains($sql, 'email')) {
            return $this->statement($recent);
        }

        return $this->statement();
    }

    /**
     * @param callable(string): PDOStatement $prepare
     */
    private function pdo(callable $prepare, ?bool $inTransaction = null, ?int &$commits = null, ?int &$rollbacks = null): PDO
    {
        $open = false;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$open, &$commits): bool {
            $open = false;
            if ($commits !== null) {
                $commits++;
            }

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open, &$rollbacks): bool {
            $open = false;
            if ($rollbacks !== null) {
                $rollbacks++;
            }

            return true;
        });
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open, $inTransaction): bool {
            return $inTransaction ?? $open;
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

    private function recovery(PDO $pdo, string $address, ?Session $session = null): AdminPasswordRecovery
    {
        $session ??= new Session([], false);
        $logger = new NullLogger();
        $tokens = new AdminResetTokens($pdo);
        $request = new Request('POST', '/admin/forgot', [], [], '', 'http://127.0.0.1', $address);

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
            ),
            new AdminAudit($pdo, new Request('GET', '/', [], [])),
            new AdminSession($session),
            new LoginSession($session),
        );
    }

    private function useMailFile(): void
    {
        $current = getenv('STALL_MAIL_FILE');
        $this->savedMailFile = is_string($current) ? $current : false;
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-admin-reset-coverage.txt';
        if (is_file($path) && !unlink($path)) {
            self::fail('the mail file could not be removed');
        }
        putenv('STALL_MAIL_FILE=' . $path);
    }
}
