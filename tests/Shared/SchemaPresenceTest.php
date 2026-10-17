<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Schema\SchemaPresence;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

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
}
