<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Schema\SchemaPresence;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaPresenceTest extends TestCase
{
    private const SCHEMA = 'tenant_0123456789abcdef0123456789abcdef';

    public function testAnEmptyCatalogIsMissingItsObjects(): void
    {
        $covered = (new SchemaPresence($this->createStub(PDO::class)))->coverage(self::SCHEMA, [
            'tables' => [],
            'routines' => [],
            'columns' => [],
            'triggers' => [],
            'constraints' => [],
        ]);

        self::assertSame(0, $covered['present']);
        self::assertSame('objects', $covered['missing']);
    }

    public function testABlankRegclassIsNotATable(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('query')->willReturn($statement);

        $covered = (new SchemaPresence($pdo))->coverage(self::SCHEMA, [
            'tables' => ['booths'],
            'routines' => [],
            'columns' => [],
            'triggers' => [],
            'constraints' => [],
        ]);

        self::assertSame(0, $covered['present']);
        self::assertSame('booths', $covered['missing']);
    }

    public function testAFailedCatalogQueryIsMissing(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('query')->willReturn(false);

        $covered = (new SchemaPresence($pdo))->coverage(self::SCHEMA, [
            'tables' => ['booths'],
            'routines' => [],
            'columns' => [],
            'triggers' => [],
            'constraints' => [],
        ]);

        self::assertSame(0, $covered['present']);
        self::assertSame('booths', $covered['missing']);
    }

    public function testASchemaThatIsNotATenantIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('query');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema name is not a tenant schema');
        (new SchemaPresence($pdo))->coverage('platform', $this->emptyCatalog());
    }

    public function testAnInvalidObjectNameIsRefused(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('query')->willReturn($statement);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $presence = new SchemaPresence($pdo);
        $catalogs = [
            ['tables' => ['Bad'], 'routines' => [], 'columns' => [], 'triggers' => [], 'constraints' => []],
            ['tables' => [], 'routines' => ['1bad'], 'columns' => [], 'triggers' => [], 'constraints' => []],
            ['tables' => [], 'routines' => [], 'columns' => [['1bad', 'id']], 'triggers' => [], 'constraints' => []],
            ['tables' => [], 'routines' => [], 'columns' => [['booths', 'Bad-Name']], 'triggers' => [], 'constraints' => []],
            ['tables' => [], 'routines' => [], 'columns' => [], 'triggers' => ['Bad'], 'constraints' => []],
            ['tables' => [], 'routines' => [], 'columns' => [], 'triggers' => [], 'constraints' => ['No-Way']],
        ];
        foreach ($catalogs as $catalog) {
            try {
                $presence->coverage(self::SCHEMA, $catalog);
                self::fail('an invalid object name was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('schema object name is invalid', $error->getMessage());
            }
        }
    }

    public function testFoundObjectsAreCountedAndTheFirstGapIsKept(): void
    {
        $found = [
            'post_sale' => 1,
            'zero_fn' => 0,
            'price_cents' => 1,
            'booths_audit' => 1,
            'booths_pkey' => 1,
        ];
        $bound = [];
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function (array $params) use (&$bound): bool {
            $bound = $params;

            return true;
        });
        $statement->method('fetchColumn')->willReturnCallback(static function () use (&$bound, $found): mixed {
            $name = $bound[count($bound) - 1] ?? '';

            return $found[$name] ?? false;
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('query')->willReturnCallback(function (string $sql): PDOStatement|false {
            if (str_contains($sql, 'missing_table')) {
                return false;
            }
            if (str_contains($sql, 'blank_table')) {
                return $this->column('');
            }
            if (str_contains($sql, 'numeric_table')) {
                return $this->column(1);
            }
            if (str_contains($sql, 'false_table')) {
                return $this->column(false);
            }

            return $this->column('reg');
        });

        $covered = (new SchemaPresence($pdo))->coverage(self::SCHEMA, [
            'tables' => ['booths', 'missing_table', 'blank_table', 'numeric_table', 'false_table'],
            'routines' => ['post_sale', 'zero_fn', 'absent_fn'],
            'columns' => [['booths', 'price_cents'], ['booths', 'missing_col']],
            'triggers' => ['booths_audit', 'absent_trg'],
            'constraints' => ['booths_pkey', 'absent_con'],
        ]);

        self::assertSame(6, $covered['present']);
        self::assertSame('missing_table', $covered['missing']);
    }

    /**
     * @return array{tables: list<string>, routines: list<string>, columns: list<array{0: string, 1: string}>, triggers: list<string>, constraints: list<string>}
     */
    private function emptyCatalog(): array
    {
        return [
            'tables' => [],
            'routines' => [],
            'columns' => [],
            'triggers' => [],
            'constraints' => [],
        ];
    }

    private function column(mixed $value): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($value);

        return $statement;
    }
}
