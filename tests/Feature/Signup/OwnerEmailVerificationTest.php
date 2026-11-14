<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Signup\OwnerEmailVerification;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OwnerEmailVerificationTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OWNER = '01900000-0000-7000-8000-000000000011';

    public function testABadOriginIsRefusedBeforeATokenIsIssued(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('beginTransaction');
        $verification = $this->verification($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The confirmation email could not be sent.');
        $verification->send(self::OWNER, 'owner@harbor.test', 'https://example.test/signup');
    }

    public function testAMailboxThatIsNotReadyIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('beginTransaction');
        $verification = $this->verification($pdo);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The confirmation email could not be sent.');
        $this->mail(function () use ($verification): void {
            $verification->send(self::OWNER, 'owner@harbor.test', 'https://example.test');
        }, [
            'STALL_MAIL_FILE' => null,
            'STALL_GMAIL_ADDRESS' => null,
            'STALL_GMAIL_APP_PASSWORD' => null,
            'STALL_SMTP_HOST' => null,
            'STALL_SMTP_USERNAME' => null,
            'STALL_SMTP_PASSWORD' => null,
            'STALL_SMTP_FROM' => null,
            'STALL_PLATFORM_ADMIN_EMAIL' => null,
        ]);
    }

    public function testAConfirmationLinkIsWrittenWhenTheMailboxAcceptsIt(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pc-signup-mail-');
        if ($file === false) {
            self::fail('the mail file could not be created');
        }
        $hashes = [];
        $verification = $this->verification($this->issuingPdo($hashes));
        try {
            $this->mail(function () use ($verification): void {
                $verification->send(self::OWNER, 'owner@harbor.test', 'https://stores.example.test');
            }, [
                'STALL_MAIL_FILE' => $file,
                'STALL_GMAIL_ADDRESS' => null,
                'STALL_GMAIL_APP_PASSWORD' => null,
                'STALL_PLATFORM_ADMIN_EMAIL' => null,
            ]);
            $body = file_get_contents($file);
            self::assertIsString($body);
            self::assertStringContainsString('https://stores.example.test/signup/verify/', $body);
            self::assertCount(1, $hashes);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $this->tokenFrom($body));
            self::assertSame(hash('sha256', $this->tokenFrom($body)), $hashes[0]);
        } finally {
            if (is_file($file) && unlink($file) !== true) {
                self::fail('the mail file could not be removed');
            }
        }
    }

    public function testARefusedMessageDoesNotLeaveTheTokenUnlogged(): void
    {
        $directory = sys_get_temp_dir();
        $hashes = [];
        $verification = $this->verification($this->issuingPdo($hashes));
        try {
            $this->mail(function () use ($verification): void {
                $verification->send(self::OWNER, 'owner@harbor.test', 'http://127.0.0.1:8080');
            }, [
                'STALL_MAIL_FILE' => $directory,
                'STALL_GMAIL_ADDRESS' => null,
                'STALL_GMAIL_APP_PASSWORD' => null,
                'STALL_PLATFORM_ADMIN_EMAIL' => null,
            ]);
            self::fail('a refused message was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('The confirmation email could not be sent.', $error->getMessage());
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
            self::assertSame('mail file write failed', $error->getPrevious()->getMessage());
        }
    }

    public function testIssuingATokenRollsBackWhenTheInsertFails(): void
    {
        $pdo = $this->transactionPdo(true);
        $pdo->method('prepare')->willReturnCallback(function (): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willThrowException(new RuntimeException('insert failed'));

            return $statement;
        });
        $verification = $this->verification($pdo);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insert failed');
        $this->mail(static function () use ($verification): void {
            $verification->send(self::OWNER, 'owner@harbor.test', 'https://example.test');
        }, ['STALL_MAIL_FILE' => null]);
    }

    public function testIssuingATokenSkipsRollbackWhenTheConnectionIsIdle(): void
    {
        $pdo = $this->transactionPdo(false);
        $pdo->expects($this->never())->method('rollBack');
        $pdo->method('prepare')->willReturnCallback(function (): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willThrowException(new RuntimeException('insert failed'));

            return $statement;
        });
        $verification = $this->verification($pdo);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insert failed');
        $verification->send(self::OWNER, 'owner@harbor.test', 'https://example.test');
    }

    public function testAConfirmationTokenWithTheWrongShapeIsIgnored(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('beginTransaction');
        $verification = $this->verification($pdo);
        self::assertNull($verification->confirm('short'));
        self::assertNull($verification->confirm(str_repeat('a', 42)));
        self::assertNull($verification->confirm(str_repeat('a', 44)));
        self::assertNull($verification->confirm(str_repeat('!', 43)));
    }

    public function testAMissingPendingRowRollsBack(): void
    {
        $pdo = $this->transactionPdo(true);
        $pdo->expects($this->once())->method('rollBack');
        $pdo->expects($this->never())->method('commit');
        $pdo->method('prepare')->willReturn($this->pending(false, 1));
        self::assertNull($this->verification($pdo)->confirm(self::TOKEN));
    }

    public function testAPendingRowWithTheWrongShapeRollsBack(): void
    {
        $pdo = $this->transactionPdo(true);
        $pdo->expects($this->once())->method('rollBack');
        $row = $this->pendingRow();
        $row['id'] = 12;
        $pdo->method('prepare')->willReturn($this->pending($row, 1));
        self::assertNull($this->verification($pdo)->confirm(self::TOKEN));

        $omitted = $this->pendingRow();
        unset($omitted['slug']);
        $second = $this->transactionPdo(true);
        $second->expects($this->once())->method('rollBack');
        $second->method('prepare')->willReturn($this->pending($omitted, 1));
        self::assertNull($this->verification($second)->confirm(self::TOKEN));
    }

    public function testAVerificationThatWasAlreadyUsedRollsBack(): void
    {
        $pdo = $this->transactionPdo(true);
        $pdo->expects($this->once())->method('rollBack');
        $pdo->expects($this->never())->method('commit');
        $pdo->method('prepare')->willReturn($this->pending($this->pendingRow(), 0));
        self::assertNull($this->verification($pdo)->confirm(self::TOKEN));
    }

    public function testAValidTokenConfirmsTheMailbox(): void
    {
        $pdo = $this->transactionPdo(true);
        $pdo->expects($this->once())->method('commit');
        $pdo->expects($this->never())->method('rollBack');
        $hashes = [];
        $updates = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$hashes, &$updates): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use ($sql, &$hashes, &$updates): bool {
                if (str_contains($sql, 'SELECT')) {
                    $hashes[] = $params[0];
                }
                if (str_starts_with(ltrim($sql), 'UPDATE')) {
                    $updates++;
                }

                return true;
            });
            $statement->method('fetch')->willReturn($this->pendingRow());
            $statement->method('rowCount')->willReturn(1);

            return $statement;
        });
        $confirmed = $this->verification($pdo)->confirm(self::TOKEN);

        self::assertSame(['email' => 'owner@harbor.test', 'slug' => 'cov-harbor'], $confirmed);
        self::assertSame([hash('sha256', self::TOKEN)], $hashes);
        self::assertSame(2, $updates);
    }

    public function testAConfirmationFailureRollsBackTheOpenTransaction(): void
    {
        $pdo = $this->transactionPdo(true);
        $pdo->expects($this->once())->method('rollBack');
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetch')->willReturn($this->pendingRow());
            $statement->method('rowCount')->willReturn(1);
            $statement->method('execute')->willReturnCallback(static function () use ($sql): bool {
                if (str_contains($sql, 'platform.owners')) {
                    throw new RuntimeException('owner update failed');
                }

                return true;
            });

            return $statement;
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owner update failed');
        $this->verification($pdo)->confirm(self::TOKEN);
    }

    public function testAConfirmationFailureOnAnIdleConnectionDoesNotRollBack(): void
    {
        $pdo = $this->transactionPdo(false);
        $pdo->expects($this->never())->method('rollBack');
        $pdo->method('prepare')->willReturnCallback(function (): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetch')->willReturn($this->pendingRow());
            $statement->method('execute')->willThrowException(new RuntimeException('select failed'));

            return $statement;
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('select failed');
        $this->verification($pdo)->confirm(self::TOKEN);
    }

    private function verification(PDO $pdo, ?AdminAudit $audit = null): OwnerEmailVerification
    {
        return new OwnerEmailVerification(
            $pdo,
            $this->mailbox(),
            $audit ?? $this->audit(1),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function mailbox(): PlatformMailbox
    {
        $logger = $this->createStub(LoggerInterface::class);
        $notice = new PlatformOperatorNotice($logger);

        return new PlatformMailbox(new OutboundMail($logger), new GmailSender($notice), $notice);
    }

    private function audit(int $stored): AdminAudit
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn($stored);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new AdminAudit($pdo, new Request('GET', '/signup/verify', [], [], '', '', '127.0.0.1'));
    }

    /**
     * @param list<string> $hashes
     */
    private function issuingPdo(array &$hashes): PDO
    {
        $pdo = $this->transactionPdo(true);
        $pdo->expects($this->once())->method('commit');
        $pdo->expects($this->never())->method('rollBack');
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$hashes): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use ($sql, &$hashes): bool {
                if (str_contains($sql, 'INSERT INTO')) {
                    $hashes[] = $params[1];
                }

                return true;
            });

            return $statement;
        });

        return $pdo;
    }

    private function transactionPdo(bool $open): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn($open);

        return $pdo;
    }

    /**
     * @return array{id: string, owner_id: string, email: string, slug: string, tenant_id: string}
     */
    private function pendingRow(): array
    {
        return [
            'id' => '01900000-0000-7000-8000-000000000010',
            'owner_id' => self::OWNER,
            'email' => 'owner@harbor.test',
            'slug' => 'cov-harbor',
            'tenant_id' => '01900000-0000-7000-8000-000000000012',
        ];
    }

    private function pending(mixed $row, int $marked): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn($row);
        $statement->method('rowCount')->willReturn($marked);

        return $statement;
    }

    private function tokenFrom(string $body): string
    {
        $found = preg_match('#/signup/verify/([A-Za-z0-9_-]{43})#', $body, $match);
        if ($found !== 1) {
            self::fail('the confirmation link was not written');
        }

        return $match[1];
    }

    /**
     * @param callable(): void $work
     * @param array<string, string|null> $env
     */
    private function mail(callable $work, array $env): void
    {
        $saved = [];
        foreach ($env as $key => $value) {
            $current = getenv($key);
            $saved[$key] = is_string($current) ? $current : null;
            if ($value === null) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }
        try {
            $work();
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    putenv($key);
                    continue;
                }
                putenv($key . '=' . $value);
            }
        }
    }
}
