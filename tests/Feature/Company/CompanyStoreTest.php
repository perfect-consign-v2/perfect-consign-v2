<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Company;

use PDO;
use PDOStatement;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyDraft;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompanyStoreTest extends TestCase
{
    public function testAMallWithoutACompanyRowIsRefused(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);
        $store = new CompanyStore(new Statements($pdo), new DatabaseRole($pdo), $pdo, new CompanyLines());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall has no company row');
        $store->profile($this->tenant());
    }

    public function testACompanySaveRefusesAMissingMallRow(): void
    {
        $pdo = $this->scripted(0, false);
        $store = new CompanyStore(new Statements($pdo), new DatabaseRole($pdo), $pdo, new CompanyLines());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall has no company row');
        $store->save($this->tenant(), CompanyDraft::parse($this->fields()));
    }

    public function testAMissingTenantRefusesTheRename(): void
    {
        $pdo = $this->scripted(1, false);
        $store = new CompanyStore(new Statements($pdo), new DatabaseRole($pdo), $pdo, new CompanyLines());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That mall was not found.');
        $store->save($this->tenant(), CompanyDraft::parse($this->fields()));
    }

    public function testAFailedCompanyCommandIsRefused(): void
    {
        $pdo = $this->scripted(1, true);
        $store = new CompanyStore(new Statements($pdo), new DatabaseRole($pdo), $pdo, new CompanyLines());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the company save could not finish');
        $store->save($this->tenant(), CompanyDraft::parse($this->fields()));
    }

    private function tenant(): TenantRecord
    {
        return new TenantRecord(
            '01900000-0000-7000-8000-0000000000c1',
            'mall',
            'tenant_00000000000000000000000000000000',
            'stall_poster',
            'Probe Mercantile',
        );
    }

    /**
     * @return array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string}
     */
    private function fields(): array
    {
        return [
            'name' => 'Probe Mercantile',
            'street' => '123 Main Street',
            'street2' => 'Suite 4',
            'city' => 'Springfield',
            'state' => 'OR',
            'postal' => '97477',
            'phone' => '541-555-0100',
            'email' => 'owner@store.test',
        ];
    }

    private function scripted(int $contactRows, bool $failCommand): PDO
    {
        $memory = new PDO('sqlite::memory:');
        $memory->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $memory->exec('CREATE TABLE mall (id INTEGER)');
        if ($contactRows === 1) {
            $memory->exec('INSERT INTO mall (id) VALUES (1)');
        }
        $contact = $memory->prepare(
            'UPDATE mall SET id = id WHERE ? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL
             AND ? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL'
        );
        $name = $memory->prepare('SELECT id FROM mall WHERE ? IS NOT NULL AND ? IS NOT NULL AND 0');
        self::assertInstanceOf(PDOStatement::class, $contact);
        self::assertInstanceOf(PDOStatement::class, $name);

        return new class($contact, $name, $failCommand) extends PDO {
            public function __construct(
                private readonly PDOStatement $contact,
                private readonly PDOStatement $name,
                private readonly bool $failCommand,
            ) {
                parent::__construct('sqlite::memory:');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return str_contains($query, 'platform.tenants') ? $this->name : $this->contact;
            }

            public function exec(string $statement): int|false
            {
                return $this->failCommand ? false : 0;
            }
        };
    }
}
