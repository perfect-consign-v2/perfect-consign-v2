<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PDO;
use PDOStatement;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Password\LoginAccounts;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\ResetLetter;
use PerfectConsign\Feature\Password\ResetRequests;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\SealedSecret;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;

final class PasswordRequestTest extends TestCase
{
    public function testARealAccountReachesTheResetMailbox(): void
    {
        $log = $this->logger();
        $recovery = $this->recovery($this->requestPdo($this->ownerRow()), $log);

        self::assertTrue($recovery->request($this->tenant(), 'owner', 'https://example.test'));
        self::assertSame('the reset mailbox could not be read', $log->message);
    }

    public function testABlankOrUnknownAccountDoesNotAskTheMailbox(): void
    {
        $idle = $this->createMock(PDO::class);
        $idle->expects($this->never())->method('prepare');
        $log = $this->logger();
        $recovery = $this->recovery($idle, $log);

        self::assertTrue($recovery->request($this->tenant(), '   ', 'https://example.test'));
        self::assertTrue($recovery->request($this->tenant(), 'Bob!', 'https://example.test'));
        self::assertSame('', $log->message);

        $missing = $this->logger();
        self::assertTrue($this->recovery($this->requestPdo(false), $missing)->request($this->tenant(), 'owner', 'https://example.test'));
        self::assertSame('', $missing->message);
    }

    public function testAFailedMailboxReadIsRefused(): void
    {
        $read = new ReflectionMethod(LoginAccounts::class, 'readMailbox');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);
        $accounts = new LoginAccounts($pdo, new DatabaseRole($pdo));
        try {
            $read->invoke($accounts, 'tenant_00000000000000000000000000000000');
            self::fail('a failed mailbox read was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('the company email could not be read', $error->getMessage());
        }

        $row = $this->createStub(PDOStatement::class);
        $row->method('fetch')->willReturn([
            'email' => 'shop@example.test',
            'receipt_mail_via' => 'server',
            'gmail_address' => '',
            'gmail_app_password' => null,
        ]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($row);
        $accounts = new LoginAccounts($pdo, new DatabaseRole($pdo));
        $mailbox = $read->invoke($accounts, 'tenant_00000000000000000000000000000000');
        self::assertIsArray($mailbox);
        self::assertSame('shop@example.test', $mailbox['email']);
        self::assertNull($mailbox['gmail']);
    }

    public function testFiveResetsInTheWindowAreRefused(): void
    {
        $count = $this->createStub(PDOStatement::class);
        $count->method('fetchColumn')->willReturn(5);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($count);
        self::assertFalse((new ResetRequests($pdo))->allow($this->tenant(), 'owner'));

        $again = $this->createStub(PDOStatement::class);
        $again->method('fetchColumn')->willReturn(4);
        $insert = $this->createStub(PDOStatement::class);
        $open = $this->createStub(PDO::class);
        $open->method('prepare')->willReturnOnConsecutiveCalls($again, $insert);
        self::assertTrue((new ResetRequests($open))->allow($this->tenant(), 'owner'));
    }

    public function testAStaffRowThatIsNotARecordIsRefused(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetch')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('exec')->willReturn(1);
        $accounts = new LoginAccounts($pdo, new DatabaseRole($pdo));
        $staff = new ReflectionMethod(LoginAccounts::class, 'staff');

        self::assertNull($staff->invoke($accounts, $this->tenant(), 'ada'));
    }

    public function testAnEmptyPasswordAndALongAddressAreNotAMailbox(): void
    {
        $accounts = (new ReflectionClass(LoginAccounts::class))->newInstanceWithoutConstructor();
        $gmail = new ReflectionMethod(LoginAccounts::class, 'gmail');
        $address = new ReflectionMethod(LoginAccounts::class, 'mailAddress');
        self::assertNull($gmail->invoke($accounts, [
            'receipt_mail_via' => 'gmail',
            'gmail_address' => 'shop@gmail.com',
            'gmail_app_password' => '',
        ]));
        $fitted = str_repeat('a', 195) . '@b.co';
        $longer = str_repeat('a', 196) . '@b.co';
        self::assertSame(200, strlen($fitted));
        self::assertSame(201, strlen($longer));
        self::assertSame($fitted, $address->invoke($accounts, $fitted));
        self::assertSame('', $address->invoke($accounts, $longer));
        $sealed = SealedSecret::seal('abcdefghijklmnop');
        $opened = $gmail->invoke($accounts, [
            'receipt_mail_via' => 'gmail',
            'gmail_address' => $fitted,
            'gmail_app_password' => $sealed,
        ]);
        self::assertIsArray($opened);
        self::assertSame($fitted, $opened['from']);
    }

    private function requestPdo(mixed $owner): PDO
    {
        $count = $this->createStub(PDOStatement::class);
        $count->method('fetchColumn')->willReturn(0);
        $insert = $this->createStub(PDOStatement::class);
        $found = $this->createStub(PDOStatement::class);
        $found->method('fetch')->willReturn($owner);
        $staff = $this->createStub(PDOStatement::class);
        $staff->method('fetch')->willReturn(false);
        $mailbox = $this->createStub(PDOStatement::class);
        $mailbox->method('fetch')->willReturn([
            'email' => 'shop@example.test',
            'receipt_mail_via' => 'gmail',
            'gmail_address' => 'shop@example.test',
            'gmail_app_password' => 'abcdefghijklmnop',
        ]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($count, $insert, $found, $staff);
        $pdo->method('query')->willReturn($mailbox);
        $pdo->method('exec')->willReturn(1);

        return $pdo;
    }

    /**
     * @return array{id: string, email: string}
     */
    private function ownerRow(): array
    {
        return [
            'id' => '01900000-0000-7000-8000-0000000000c1',
            'email' => 'owner@example.test',
        ];
    }

    private function recovery(PDO $pdo, AbstractLogger $log): PasswordRecovery
    {
        return new PasswordRecovery(
            $pdo,
            new DatabaseRole($pdo),
            new LoginAccounts($pdo, new DatabaseRole($pdo)),
            new ResetTokens($pdo),
            new ResetRequests($pdo),
            new PasswordChoice(),
            \PerfectConsign\Tests\Support\MailTestKit::resetLetter($pdo, $log),
        );
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public string $message = '';

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->message = (string) $message;
            }
        };
    }

    private function tenant(): TenantRecord
    {
        return new TenantRecord(
            '01900000-0000-7000-8000-0000000000c1',
            'demo',
            'tenant_00000000000000000000000000000000',
            'stall_poster',
            'Demo Mall',
        );
    }
}
