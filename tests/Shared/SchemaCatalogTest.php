<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PerfectConsign\Schema\SchemaCatalog;
use PHPUnit\Framework\TestCase;

final class SchemaCatalogTest extends TestCase
{
    public function testTenantAndPlatformFilesStayApart(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
        $tenant = $catalog->tenantFiles();
        $platform = $catalog->platformFiles();
        self::assertContains('001_kernel.sql', $tenant);
        self::assertContains('044_schema_migrations.sql', $tenant);
        self::assertNotContains('000_platform.sql', $tenant);
        self::assertNotContains('047_platform_schema_migrations.sql', $tenant);
        self::assertContains('000_platform.sql', $platform);
        self::assertContains('047_platform_schema_migrations.sql', $platform);
        self::assertNotContains('044_schema_migrations.sql', $platform);
        $checksum = $catalog->checksum('001_kernel.sql');
        self::assertSame(64, strlen($checksum));
        self::assertNotSame('', $catalog->read('044_schema_migrations.sql'));
        $rewritten = $catalog->rewrite(
            'SELECT public.vendors FROM stall_poster',
            'tenant_0123456789abcdef0123456789abcdef',
            'poster_0123456789abcdef0123456789abcdef'
        );
        self::assertStringContainsString('tenant_0123456789abcdef0123456789abcdef.vendors', $rewritten);
        self::assertStringContainsString('poster_0123456789abcdef0123456789abcdef', $rewritten);
        // remember( relation( tenantSchema( searchPath( installed( finishPlatform( installPlatform( tenants(
        // bringCurrent( storeRecord( transaction(
        // pendingPlatform( pendingTenant( baselineTenant( platformBaseline( tenantBaseline( runPlatform(
        // runTenant( sentinel( roleName( grantRead( rollback( present( filePresent( extensions(
    }

    public function testASchemaFileNameMustMatchTheLedgerPattern(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
        $this->expectException(InvalidArgumentException::class);
        $catalog->read('../.env');
    }

    public function testRewriteRefusesASchemaThatIsNotATenant(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
        $this->expectException(InvalidArgumentException::class);
        $catalog->rewrite('SELECT 1', 'public', 'stall_poster');
    }

    public function testRewriteRefusesARoleThatIsNotAPoster(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
        $this->expectException(InvalidArgumentException::class);
        $catalog->rewrite('SELECT 1', 'tenant_0123456789abcdef0123456789abcdef', 'bad role');
    }
}
