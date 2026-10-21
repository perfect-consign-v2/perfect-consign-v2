<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PDO;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Password\LoginAccount;
use PerfectConsign\Feature\Password\LoginAccounts;
use PerfectConsign\Feature\Password\OwnPassword;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Password\ResetLetter;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Feature\Password\ResetRequests;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

final class PasswordBoundaryTest extends TestCase
{
    public function testAStaffTableNameMustBeATenantSchema(): void
    {
        $accounts = (new \ReflectionClass(LoginAccounts::class))->newInstanceWithoutConstructor();
        $table = new ReflectionMethod(LoginAccounts::class, 'table');
        try {
            $table->invoke($accounts, 'public');
            self::fail('a public schema was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }

        $own = (new \ReflectionClass(OwnPassword::class))->newInstanceWithoutConstructor();
        $ownTable = new ReflectionMethod(OwnPassword::class, 'table');
        try {
            $ownTable->invoke($own, 'public');
            self::fail('a public schema was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }

        $recovery = (new \ReflectionClass(PasswordRecovery::class))->newInstanceWithoutConstructor();
        $recoveryTable = new ReflectionMethod(PasswordRecovery::class, 'table');
        try {
            $recoveryTable->invoke($recovery, 'public');
            self::fail('a public schema was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }
    }

    public function testAMissingOriginDoesNotIssueAReset(): void
    {
        $log = new class extends AbstractLogger {
            public string $message = '';

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->message = (string) $message;
            }
        };
        $letter = new ResetLetter(
            new ResetTokens($this->createStub(PDO::class)),
            new OutboundMail($log),
            $log
        );
        $letter->deliver($this->tenant(), new LoginAccount('staff', '01900000-0000-7000-8000-0000000000c1', 'cashier', 'c@example.com'), 'not-a-url');
        self::assertSame('reset link origin is missing', $log->message);
    }

    public function testAnOwnerWithoutARecoveryEmailUsesTheCompanyAddress(): void
    {
        $recovery = (new \ReflectionClass(PasswordRecovery::class))->newInstanceWithoutConstructor();
        $destination = new ReflectionMethod(PasswordRecovery::class, 'destination');
        $owner = new LoginAccount('owner', '01900000-0000-7000-8000-0000000000c1', 'owner', '');
        self::assertSame('store@example.com', $destination->invoke($recovery, $owner, 'store@example.com'));
        $staff = new LoginAccount('staff', '01900000-0000-7000-8000-0000000000c1', 'cashier', '');
        self::assertSame('', $destination->invoke($recovery, $staff, 'store@example.com'));
        $saved = new LoginAccount('owner', '01900000-0000-7000-8000-0000000000c1', 'owner', 'owner@example.com');
        self::assertSame('owner@example.com', $destination->invoke($recovery, $saved, 'store@example.com'));

        $gmail = new ReflectionMethod(PasswordRecovery::class, 'gmail');
        self::assertNull($gmail->invoke($recovery, [
            'receipt_mail_via' => 'server',
            'gmail_address' => 'shop@gmail.com',
            'gmail_app_password' => 'abcdefghijklmnop',
        ]));
        $account = $gmail->invoke($recovery, [
            'receipt_mail_via' => 'gmail',
            'gmail_address' => 'shop@gmail.com',
            'gmail_app_password' => 'abcdefghijklmnop',
        ]);
        self::assertIsArray($account);
        self::assertSame('smtp.gmail.com', $account['host']);
        self::assertSame(587, $account['port']);
        self::assertSame('shop@gmail.com', $account['from']);
        self::assertNull($gmail->invoke($recovery, [
            'receipt_mail_via' => 'gmail',
            'gmail_address' => 'not-an-email',
            'gmail_app_password' => 'abcdefghijklmnop',
        ]));
    }

    public function testARecordedResetSkipsGmail(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-reset-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $path);
        $log = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };
        $letter = new ResetLetter(
            new ResetTokens($this->createStub(PDO::class)),
            new OutboundMail($log),
            $log,
        );
        $send = new ReflectionMethod(ResetLetter::class, 'send');
        try {
            $send->invoke($letter, 'owner-reset@example.com', 'Reset your password', "Open this link\n", [
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'username' => 'shop@gmail.com',
                'password' => 'abcdefghijklmnop',
                'from' => 'shop@gmail.com',
            ]);
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertStringContainsString('To: owner-reset@example.com', $text);
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_MAIL_FILE=' . $previous);
            } else {
                putenv('STALL_MAIL_FILE');
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAClosedResetLinkAsksForANewOne(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Password/reset', [
            'slug' => 'demo',
            'csrf' => 'token',
            'token' => str_repeat('a', 43),
            'open' => false,
        ]);
        $closed = ob_get_clean();
        ob_start();
        $view->render('Feature/Password/reset', [
            'slug' => 'demo',
            'csrf' => 'token',
            'token' => str_repeat('a', 43),
            'open' => true,
        ]);
        $open = ob_get_clean();
        self::assertIsString($closed);
        self::assertStringContainsString('/demo/forgot', $closed);
        self::assertIsString($open);
        self::assertStringContainsString('name="password"', $open);
        self::assertStringContainsString('name="confirm"', $open);
    }

    public function testAResetTokenRollbackLeavesAnIdleConnection(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $tokens = new ResetTokens($pdo);
        $roll = new ReflectionMethod(ResetTokens::class, 'rollback');
        $roll->invoke($tokens);
        self::assertTrue(true);
    }

    public function testStaffEmailAndHashBoundaries(): void
    {
        $hash = password_hash('current-pass', PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $found = $this->createStub(\PDOStatement::class);
        $found->method('fetchColumn')->willReturn($hash);
        $found->method('rowCount')->willReturn(0);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturn($found);
        $own = new OwnPassword(
            $pdo,
            new DatabaseRole($pdo),
            new PasswordChoice(),
            new RecoveryAddress(),
        );
        $tenant = $this->tenant();
        try {
            $own->emailStaff($tenant, '01900000-0000-7000-8000-0000000000c1', 'current-pass', 'staff@example.com');
            self::fail('a missing staff row saved an email');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in was not found.', $error->getMessage());
        }

        $missingHash = $this->createStub(\PDOStatement::class);
        $missingHash->method('fetchColumn')->willReturn(false);
        $empty = $this->createStub(PDO::class);
        $empty->method('exec')->willReturn(1);
        $empty->method('prepare')->willReturn($missingHash);
        $blank = new OwnPassword(
            $empty,
            new DatabaseRole($empty),
            new PasswordChoice(),
            new RecoveryAddress(),
        );
        try {
            $blank->changeStaff($tenant, '01900000-0000-7000-8000-0000000000c1', 'current-pass', 'next-pass1', 'next-pass1');
            self::fail('a missing hash was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in was not found.', $error->getMessage());
        }

        $badVersion = $this->createStub(\PDOStatement::class);
        $badVersion->method('fetchColumn')->willReturnOnConsecutiveCalls($hash, 'not-a-version');
        $versionPdo = $this->createStub(PDO::class);
        $versionPdo->method('exec')->willReturn(1);
        $versionPdo->method('prepare')->willReturn($badVersion);
        $versioned = new OwnPassword(
            $versionPdo,
            new DatabaseRole($versionPdo),
            new PasswordChoice(),
            new RecoveryAddress(),
        );
        try {
            $versioned->changeStaff($tenant, '01900000-0000-7000-8000-0000000000c1', 'current-pass', 'next-pass1', 'next-pass1');
            self::fail('a non-numeric credentials version was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in was not found.', $error->getMessage());
        }
    }

    public function testAMissingOwnerEmailRowIsRefused(): void
    {
        $hash = password_hash('current-pass', PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($hash);
        $statement->method('rowCount')->willReturn(0);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturn($statement);
        $own = new OwnPassword(
            $pdo,
            new DatabaseRole($pdo),
            new PasswordChoice(),
            new RecoveryAddress(),
        );
        try {
            $own->emailOwner($this->tenant(), '01900000-0000-7000-8000-0000000000c1', 'current-pass', 'owner@example.com');
            self::fail('a missing owner row saved an email');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in was not found.', $error->getMessage());
        }
    }

    public function testRecoveryWritesOwnerAndStaffRows(): void
    {
        $hash = password_hash('old-password', PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $token = str_repeat('a', 43);
        $lock = $this->createStub(\PDOStatement::class);
        $lock->method('fetch')->willReturn([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'owner',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]);
        $identity = $this->createStub(\PDOStatement::class);
        $identity->method('fetch')->willReturn([
            'username' => 'owner',
            'password_hash' => $hash,
        ]);
        $write = $this->createStub(\PDOStatement::class);
        $write->method('rowCount')->willReturn(0);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($lock, $identity, $write);
        try {
            $this->recovery($pdo)->redeem($this->tenant(), $token, 'new-password', 'new-password');
            self::fail('a missing owner write was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(ResetNotice::EXPIRED, $error->getMessage());
        }
    }

    public function testRecoveryStaffIdentityNeedsAHash(): void
    {
        $token = str_repeat('b', 43);
        $lock = $this->createStub(\PDOStatement::class);
        $lock->method('fetch')->willReturn([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'staff',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]);
        $identity = $this->createStub(\PDOStatement::class);
        $identity->method('fetch')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($lock, $identity);
        try {
            $this->recovery($pdo)->redeem($this->tenant(), $token, 'new-password', 'new-password');
            self::fail('a missing staff identity was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(ResetNotice::EXPIRED, $error->getMessage());
        }
    }

    public function testRecoveryStaffWriteMustUpdateOneRow(): void
    {
        $hash = password_hash('old-password', PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $token = str_repeat('c', 43);
        $lock = $this->createStub(\PDOStatement::class);
        $lock->method('fetch')->willReturn([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'staff',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]);
        $identity = $this->createStub(\PDOStatement::class);
        $identity->method('fetch')->willReturn([
            'username' => 'cashier',
            'password_hash' => $hash,
        ]);
        $write = $this->createStub(\PDOStatement::class);
        $write->method('rowCount')->willReturn(0);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($lock, $identity, $write);
        try {
            $this->recovery($pdo)->redeem($this->tenant(), $token, 'new-password', 'new-password');
            self::fail('a missing staff write was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(ResetNotice::EXPIRED, $error->getMessage());
        }
    }

    private function recovery(PDO $pdo): PasswordRecovery
    {
        return new PasswordRecovery(
            $pdo,
            new DatabaseRole($pdo),
            (new \ReflectionClass(LoginAccounts::class))->newInstanceWithoutConstructor(),
            new ResetTokens($pdo),
            (new \ReflectionClass(ResetRequests::class))->newInstanceWithoutConstructor(),
            new PasswordChoice(),
            (new \ReflectionClass(ResetLetter::class))->newInstanceWithoutConstructor(),
        );
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
