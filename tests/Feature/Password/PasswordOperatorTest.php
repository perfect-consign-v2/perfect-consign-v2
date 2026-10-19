<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PDO;
use PDOStatement;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Password\OwnPassword;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PasswordOperatorTest extends TestCase
{
    public function testAPasswordHashAndVersionStayStrings(): void
    {
        $own = (new ReflectionClass(OwnPassword::class))->newInstanceWithoutConstructor();
        $hash = new ReflectionMethod(OwnPassword::class, 'hash');
        $version = new ReflectionMethod(OwnPassword::class, 'version');
        self::assertSame('secret', $hash->invoke($own, 'secret'));
        self::assertSame('4', $version->invoke($own, '4'));
        self::assertSame('4', $version->invoke($own, 4));
        try {
            $hash->invoke($own, '');
            self::fail('an empty hash was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in was not found.', $error->getMessage());
        }
        try {
            $version->invoke($own, '');
            self::fail('an empty version was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in was not found.', $error->getMessage());
        }
    }

    public function testAResetLockNeedsOwnerOrStaffIds(): void
    {
        $tenant = $this->tenant();
        $idle = $this->createMock(PDO::class);
        $idle->expects(self::never())->method('prepare');
        self::assertNull((new ResetTokens($idle))->lock($tenant, 'short'));

        self::assertNull((new ResetTokens($this->pdo($this->fetch('nope'))))->lock($tenant, $this->token()));
        self::assertNull((new ResetTokens($this->pdo($this->fetch([
            'id' => 4,
            'account_kind' => 'owner',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]))))->lock($tenant, $this->token()));
        self::assertNull((new ResetTokens($this->pdo($this->fetch([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'owner',
            'account_id' => 4,
        ]))))->lock($tenant, $this->token()));
        self::assertNull((new ResetTokens($this->pdo($this->fetch([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'vendor',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]))))->lock($tenant, $this->token()));
        self::assertSame('owner', (new ResetTokens($this->pdo($this->fetch([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'owner',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]))))->lock($tenant, $this->token())['kind'] ?? null);
        self::assertSame('staff', (new ResetTokens($this->pdo($this->fetch([
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'account_kind' => 'staff',
            'account_id' => '01900000-0000-7000-8000-0000000000c1',
        ]))))->lock($tenant, $this->token())['kind'] ?? null);
    }

    public function testAMissingLockAsksForANewReset(): void
    {
        $pdo = $this->pdo($this->fetch(false));
        $pdo->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn(true);
        try {
            $this->recovery($pdo)->redeem($this->tenant(), $this->token(), 'new-password', 'new-password');
            self::fail('a missing lock was redeemed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(ResetNotice::EXPIRED, $error->getMessage());
        }
    }

    public function testOwnerAndStaffResetsWriteTheMatchingTable(): void
    {
        $sql = '';
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('rowCount')->willReturn(1);
        $statement->method('fetch')->willReturn([
            'username' => 'owner',
            'password_hash' => password_hash('old-password', PASSWORD_DEFAULT),
        ]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturnCallback(static function (string $query) use (&$sql, $statement): PDOStatement {
            $sql = $query;

            return $statement;
        });
        $recovery = $this->recovery($pdo);
        $replace = new ReflectionMethod(PasswordRecovery::class, 'replace');
        $identity = new ReflectionMethod(PasswordRecovery::class, 'identity');
        $hash = password_hash('old-password', PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $replace->invoke($recovery, $this->tenant(), $this->locked('owner'), $hash, 'new-password', 'new-password');
        self::assertStringContainsString('platform.owners', $sql);
        $replace->invoke($recovery, $this->tenant(), $this->locked('staff'), $hash, 'new-password', 'new-password');
        self::assertStringContainsString('tenant_00000000000000000000000000000000.staff', $sql);
        $owner = $identity->invoke($recovery, $this->tenant(), $this->locked('owner'));
        self::assertSame('owner', $owner['username']);
        self::assertStringContainsString('platform.owners', $sql);
        $staff = $identity->invoke($recovery, $this->tenant(), $this->locked('staff'));
        self::assertSame('owner', $staff['username']);
        self::assertStringContainsString('tenant_00000000000000000000000000000000.staff', $sql);
    }

    public function testAResetIdentityNeedsBothStrings(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturn($statement);
        $identity = new ReflectionMethod(PasswordRecovery::class, 'identity');
        $recovery = $this->recovery($pdo);
        $rows = [
            ['username' => 4, 'password_hash' => 'hash'],
            ['username' => 'owner', 'password_hash' => 4],
            ['username' => 'owner', 'password_hash' => ''],
        ];
        foreach ($rows as $row) {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetch')->willReturn($row);
            $pdo = $this->createStub(PDO::class);
            $pdo->method('exec')->willReturn(1);
            $pdo->method('prepare')->willReturn($statement);
            try {
                $identity->invoke($this->recovery($pdo), $this->tenant(), $this->locked('owner'));
                self::fail('a broken identity was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(ResetNotice::EXPIRED, $error->getMessage());
            }
        }
        self::assertInstanceOf(PasswordRecovery::class, $recovery);
    }

    private function recovery(PDO $pdo): PasswordRecovery
    {
        return new PasswordRecovery(
            $pdo,
            new DatabaseRole($pdo),
            (new ReflectionClass(\PerfectConsign\Feature\Password\LoginAccounts::class))->newInstanceWithoutConstructor(),
            new ResetTokens($pdo),
            (new ReflectionClass(\PerfectConsign\Feature\Password\ResetRequests::class))->newInstanceWithoutConstructor(),
            new PasswordChoice(),
            (new ReflectionClass(\PerfectConsign\Feature\Password\ResetLetter::class))->newInstanceWithoutConstructor(),
        );
    }

    private function pdo(PDOStatement $statement): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function fetch(mixed $row): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetch')->willReturn($row);

        return $statement;
    }

    /**
     * @return array{id: string, kind: string, account: string}
     */
    private function locked(string $kind): array
    {
        return [
            'id' => '01900000-0000-7000-8000-0000000000r1',
            'kind' => $kind,
            'account' => '01900000-0000-7000-8000-0000000000c1',
        ];
    }

    private function token(): string
    {
        return str_repeat('a', 43);
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
