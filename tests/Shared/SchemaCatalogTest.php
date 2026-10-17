<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\SqlObjects;
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
        $objects = $catalog->baselineObjects();
        self::assertContains('journal_cash_tendered', $objects['tables']);
        self::assertContains('stall_reverse_payout_run', $objects['routines']);
        self::assertNotContains('schema_migrations', $objects['tables']);
        $attributes = $catalog->fileObjects('048_item_attributes.sql');
        self::assertContains('colors', $attributes['tables']);
        self::assertContains(['items', 'color_id'], $attributes['columns']);
        self::assertContains('items_reject_disabled_attribute', $attributes['routines']);
        self::assertContains('items_disabled_attribute', $attributes['triggers']);
        $stock = $catalog->fileObjects('049_label_stock.sql');
        self::assertContains(['mall', 'label_stock'], $stock['columns']);
        self::assertContains('mall_label_stock_chk', $stock['constraints']);
        $footer = $catalog->fileObjects('065_receipt_footer.sql');
        self::assertContains(['mall', 'receipt_footer'], $footer['columns']);
        self::assertContains('mall_receipt_footer_chk', $footer['constraints']);
        $qualified = (new SqlObjects())->from(
            "CREATE TABLE public.kept (id int);\n"
            . "CREATE TABLE other.skipped (id int);\n"
            . "CREATE FUNCTION public.kept_fn() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$;\n"
            . "CREATE FUNCTION hidden.nope() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$;\n"
            . "ALTER TABLE public.items ADD COLUMN kept_col int;\n"
            . "ALTER TABLE hidden.secret ADD COLUMN hidden_col int;\n"
        );
        self::assertContains('kept', $qualified['tables']);
        self::assertNotContains('skipped', $qualified['tables']);
        self::assertContains('kept_fn', $qualified['routines']);
        self::assertNotContains('nope', $qualified['routines']);
        self::assertContains(['items', 'kept_col'], $qualified['columns']);
        self::assertNotContains(['secret', 'hidden_col'], $qualified['columns']);
        // remember( relation( tenantSchema( searchPath( installed( finishPlatform( installPlatform( tenants(
        // bringCurrent( storeRecord( transaction( requireBaseline( holdMigration( releaseMigration( window(
        // absent( regclass( routine( identifier( baselineObjects( fileObjects( from( definitions( created(
        // columns( alteredTable( names( unique( byColumn( coverage( mark( column( triggerName( constraint( schema(
        // stampPresent( getCatalogLinks( getEnabledLists( postedAttributes( fillNames( accept(
        // labels( printOwn( storeName( sticker( numbers( owns( sum(
        // requireVendor( requireBrand( requireAttributes( __construct(
        // pendingPlatform( pendingTenant( baselineTenant( platformBaseline( tenantBaseline( runPlatform(
        // runTenant( roleName( grantRead( rollback( present( filePresent( extensions(
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
