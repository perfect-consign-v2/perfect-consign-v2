<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminEnterAs;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminEnterAsCoverageTest extends TestCase
{
    private const ADMIN_ID = '01900000-0000-7000-8000-0000000000aa';

    private const OWNER_ID = '01900000-0000-7000-8000-0000000000bb';

    private const TENANT_ID = '01900000-0000-7000-8000-0000000000cc';

    private const SCHEMA = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROLE = 'poster_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testARoleOutsideTheCounterIsRefusedBeforeASessionOpens(): void
    {
        $enter = $this->enter($this->quietPdo());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose owner, cashier, or manager.');
        $enter->enter($this->admin(), $this->tenant(), 'vendor');
    }

    public function testStaffEntryRefusesARoleThePublicMethodAlreadyFiltered(): void
    {
        $enter = $this->enter($this->quietPdo());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose owner, cashier, or manager.');
        $this->invoke($enter, 'asStaff', $this->admin(), $this->tenant(), $this->preserved(), 'owner');
    }

    public function testAnOwnerIdThatIsNotAUuidIsRefused(): void
    {
        $enter = $this->enter($this->quietPdo());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose an owner.');
        $enter->enter($this->admin(), $this->tenant(), 'owner', 'not-a-uuid');
    }

    public function testAMissingOwnerIsAuditedAndRefused(): void
    {
        $enter = $this->enter($this->ownerPdo(false));
        try {
            $enter->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID);
            self::fail('a missing owner was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That store has no owner.', $error->getMessage());
        }
    }

    public function testAnUnusableOwnerRowIsRefused(): void
    {
        $enter = $this->enter($this->ownerPdo([
            'id' => 12,
            'username' => 'owner',
            'credentials_version' => '1',
            'can_sign_in' => true,
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the owner row is not usable');
        $enter->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID);
    }

    public function testOwnerSignInFlagsCoverEveryBooleanSpelling(): void
    {
        $allowed = [true, 't', 'true', 1, '1'];
        foreach ($allowed as $flag) {
            $version = $flag === true ? 4 : '4';
            $enter = $this->enter($this->ownerPdo($this->ownerRow($flag, $version)));
            self::assertSame('/zcov-enter/dashboard', $enter->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID));
        }
        foreach ([false, 'f', 'false', 0, '0'] as $flag) {
            $enter = $this->enter($this->ownerPdo($this->ownerRow($flag, '4')));
            try {
                $enter->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID);
                self::fail('a suspended owner was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('That owner is suspended.', $error->getMessage());
            }
        }
        $enter = $this->enter($this->ownerPdo($this->ownerRow('yes', '4')));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the owner row is not usable');
        $enter->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID);
    }

    public function testTheMallSearchPathMustBeSetAndCleared(): void
    {
        $blocked = $this->enter($this->ownerPdo($this->ownerRow(true, '4'), 'SET search_path'));
        try {
            $blocked->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID);
            self::fail('a failed search path was ignored');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall search path could not be set', $error->getMessage());
        }

        $stuck = $this->enter($this->ownerPdo($this->ownerRow(true, '4'), 'RESET search_path'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the search path could not be reset');
        $stuck->enter($this->admin(), $this->tenant(), 'owner', self::OWNER_ID);
    }

    public function testAMallWithoutThatStaffRoleIsAudited(): void
    {
        $enter = $this->enter($this->staffPdo(false));
        try {
            $enter->enter($this->admin(), $this->tenant(), 'cashier');
            self::fail('a missing cashier was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That store has no cashier.', $error->getMessage());
        }
    }

    public function testAStaffSchemaThatIsNotATenantSchemaIsRefused(): void
    {
        $enter = $this->enter($this->quietPdo());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mall schema is not usable');
        $enter->enter($this->admin(), $this->tenant('not_a_schema'), 'manager');
    }

    public function testAnUnusableStaffRowIsRefused(): void
    {
        $enter = $this->enter($this->staffPdo([
            'id' => self::OWNER_ID,
            'username' => 'till',
            'display_name' => 4,
            'credentials_version' => '2',
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the manager row is not usable');
        $enter->enter($this->admin(), $this->tenant(), 'manager');
    }

    public function testAManagerWithAnIntegerCredentialVersionOpensTheCounter(): void
    {
        $enter = $this->enter($this->staffPdo([
            'id' => self::OWNER_ID,
            'username' => 'till',
            'display_name' => 'Till',
            'credentials_version' => 9,
        ]));
        self::assertSame('/zcov-enter/pos', $enter->enter($this->admin(), $this->tenant(), 'manager'));
    }

    private function invoke(AdminEnterAs $enter, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(AdminEnterAs::class, $method))->invoke($enter, ...$args);
    }

    private function enter(PDO $pdo): AdminEnterAs
    {
        $session = $this->session([
            AdminSession::ID => self::ADMIN_ID,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => (string) time(),
            'platform_last_seen_at' => (string) time(),
        ]);

        return new AdminEnterAs(
            $pdo,
            new DatabaseRole($pdo),
            new LoginSession($session),
            new AdminSession($session),
            new AdminAudit($pdo, new Request('POST', '/admin/tenants/zcov-enter/enter', [], [], '', '', '127.0.0.1')),
            new Statements($pdo),
        );
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function session(array $bag): Session
    {
        return new class ($bag) extends Session {
            /** @param array<string, mixed> $bag */
            public function __construct(private array $bag)
            {
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->bag[$key]);
            }

            public function regenerateId(bool $deleteOldSession = true): void
            {
            }
        };
    }

    private function quietPdo(): PDO
    {
        return $this->scripted(
            fn (string $sql): PDOStatement => $this->statement(false),
            static fn (string $sql): int => 0,
        );
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function ownerPdo(array|false $row, ?string $failExec = null): PDO
    {
        return $this->scripted(
            function (string $sql) use ($row): PDOStatement {
                if (str_contains($sql, 'platform.owners')) {
                    return $this->statement($row);
                }

                return $this->statement(false);
            },
            static function (string $sql) use ($failExec): int|false {
                if ($failExec !== null && str_starts_with($sql, $failExec)) {
                    return false;
                }

                return 0;
            },
        );
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function staffPdo(array|false $row): PDO
    {
        return $this->scripted(
            function (string $sql) use ($row): PDOStatement {
                if (str_contains($sql, '.staff')) {
                    return $this->statement($row);
                }

                return $this->statement(false);
            },
            static fn (string $sql): int => 0,
        );
    }

    /**
     * @param callable(string): PDOStatement $prepare
     * @param callable(string): (int|false) $exec
     */
    private function scripted(callable $prepare, callable $exec): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback($prepare);
        $pdo->method('exec')->willReturnCallback($exec);

        return $pdo;
    }

    private function statement(mixed $row): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn($row);
        $statement->method('rowCount')->willReturn(1);

        return $statement;
    }

    /**
     * @return array{id: string, email: string, version: string}
     */
    private function admin(): array
    {
        return ['id' => self::ADMIN_ID, 'email' => 'admin@example.test', 'version' => '1'];
    }

    /**
     * @return array{id: string, email: string, version: string, signed_in_at: string, last_seen_at: string}
     */
    private function preserved(): array
    {
        $now = (string) time();

        return [
            'id' => self::ADMIN_ID,
            'email' => 'admin@example.test',
            'version' => '1',
            'signed_in_at' => $now,
            'last_seen_at' => $now,
        ];
    }

    private function tenant(string $schema = self::SCHEMA): TenantRecord
    {
        return new TenantRecord(self::TENANT_ID, 'zcov-enter', $schema, self::ROLE, 'Zcov');
    }

    /**
     * @return array{id: string, username: string, credentials_version: int|string, can_sign_in: mixed}
     */
    private function ownerRow(mixed $flag, int|string $version): array
    {
        return [
            'id' => self::OWNER_ID,
            'username' => 'owner',
            'credentials_version' => $version,
            'can_sign_in' => $flag,
        ];
    }
}
