<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Company;

use PDO;
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
        [$pdo, $statements] = $this->scripted(0, false);
        $store = new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall has no company row');
        $store->save($this->tenant(), CompanyDraft::parse($this->fields()));
    }

    public function testAMissingTenantRefusesTheRename(): void
    {
        [$pdo, $statements] = $this->scripted(1, false);
        $store = new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That mall was not found.');
        $store->save($this->tenant(), CompanyDraft::parse($this->fields()));
    }

    public function testAFailedCompanyCommandIsRefused(): void
    {
        [$pdo, $statements] = $this->scripted(1, true);
        $store = new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines());

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

    /**
     * @return array{PDO, Statements}
     */
    private function scripted(int $contactRows, bool $failCommand): array
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn($failCommand ? false : 0);

        return [$pdo, new class($pdo, $contactRows) extends Statements {
            public function __construct(PDO $pdo, private readonly int $contactRows)
            {
                parent::__construct($pdo);
            }

            public function transaction(callable $work): void
            {
                $work();
            }

            /**
             * @param list<mixed> $params
             */
            public function affected(string $sql, array $params): int
            {
                return $this->contactRows;
            }

            /**
             * @param list<mixed> $params
             * @return list<array<string, mixed>>
             */
            public function bound(string $sql, array $params): array
            {
                return [];
            }
        }];
    }
}
