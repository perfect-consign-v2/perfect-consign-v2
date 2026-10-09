<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\SchemaDump;
use PHPUnit\Framework\TestCase;

final class SchemaDumpTest extends TestCase
{
    public function testADumpIsRewrittenOntoTheNewSchema(): void
    {
        $from = 'tenant_' . str_repeat('a', 32);
        $to = 'tenant_' . str_repeat('b', 32);

        self::assertSame(
            'CREATE SCHEMA ' . $to,
            SchemaDump::rewrite('CREATE SCHEMA ' . $from, $from, $to)
        );
    }

    public function testADumpThatDoesNotNameItsSchemaIsRefused(): void
    {
        $from = 'tenant_' . str_repeat('a', 32);
        $to = 'tenant_' . str_repeat('b', 32);

        $this->expectException(BackupException::class);
        SchemaDump::rewrite('CREATE SCHEMA public', $from, $to);
    }

    public function testASchemaNameThatIsNotATenantIsRefused(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('schema name is not a tenant schema');
        SchemaDump::rewrite('CREATE SCHEMA public', 'public', 'public');
    }

    public function testOneSchemaNameThatIsNotATenantIsRefused(): void
    {
        $to = 'tenant_' . str_repeat('b', 32);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('schema name is not a tenant schema');
        SchemaDump::rewrite('CREATE SCHEMA public', 'public', $to);
    }
}
