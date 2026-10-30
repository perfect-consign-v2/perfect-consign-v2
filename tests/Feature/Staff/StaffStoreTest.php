<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Staff;

use PDO;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class StaffStoreTest extends TestCase
{
    public function testABooksLoginNeedsAValidId(): void
    {
        $store = $this->store(0);
        try {
            $store->replaceBooks('not-an-id', 'password1', 'books@example.com');
            self::fail('a blank books id was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a books login.', $error->getMessage());
        }
        try {
            $store->replaceBooks('01900000-0000-7000-8000-0000000000b1', 'password1', 'books@example.com');
            self::fail('a missing books login was updated');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a books login.', $error->getMessage());
        }
        $store->addBooks('books-one', 'Books One', 'password1', 'books@example.com');
        self::assertSame('books', $this->lastRole);
        $store->addManager('mgr-one', 'Manager One', 'password1', 'mgr@example.com');
        self::assertSame('manager', $this->lastRole);
        $saved = $this->store(1);
        $saved->replaceBooks('01900000-0000-7000-8000-0000000000b1', 'password1', 'books@example.com');
        $saved->replaceManager('01900000-0000-7000-8000-0000000000a1', 'password1', 'mgr@example.com');
        $saved->replaceCashier('01900000-0000-7000-8000-0000000000c1', 'password1', 'c@example.com');
        $saved->addCashier('cashier-one', str_repeat('A', 80), 'password1', 'c@example.com');
        try {
            $saved->addCashier('cashier-two', '', 'password1', 'c@example.com');
            self::fail('a blank display name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter the name people will see.', $error->getMessage());
        }
        try {
            $saved->addCashier('cashier-two', str_repeat('A', 81), 'password1', 'c@example.com');
            self::fail('an 81 character display name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter the name people will see.', $error->getMessage());
        }
    }

    public function testACashierReplaceNeedsAMatchingRow(): void
    {
        $store = $this->store(0);
        try {
            $store->replaceCashier('not-an-id', 'password1', 'c@example.com');
            self::fail('a blank cashier id was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a cashier.', $error->getMessage());
        }
        try {
            $store->replaceCashier('01900000-0000-7000-8000-0000000000c1', 'password1', 'c@example.com');
            self::fail('a missing cashier was updated');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a cashier.', $error->getMessage());
        }
    }

    public function testAManagerReplaceNeedsAMatchingRow(): void
    {
        $store = $this->store(0);
        try {
            $store->replaceManager('not-an-id', 'password1', 'mgr@example.com');
            self::fail('a blank manager id was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a manager.', $error->getMessage());
        }
        try {
            $store->replaceManager('01900000-0000-7000-8000-0000000000a1', 'password1', 'mgr@example.com');
            self::fail('a missing manager was updated');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a manager.', $error->getMessage());
        }
    }

    private string $lastRole = '';

    private function store(int $affected): StaffStore
    {
        $statements = new class ($this->createStub(PDO::class), $affected, $this) extends Statements {
            public function __construct(PDO $pdo, private readonly int $rows, private readonly StaffStoreTest $test)
            {
                parent::__construct($pdo);
            }

            public function affected(string $sql, array $params): int
            {
                return $this->rows;
            }

            public function change(string $sql, array $params): void
            {
                $role = $params[3] ?? '';
                if (is_string($role)) {
                    $this->test->rememberRole($role);
                }
            }

            public function bound(string $sql, array $params): array
            {
                return [];
            }
        };

        return new StaffStore($statements, new RecoveryAddress(), new PasswordChoice());
    }

    public function rememberRole(string $role): void
    {
        $this->lastRole = $role;
    }
}
