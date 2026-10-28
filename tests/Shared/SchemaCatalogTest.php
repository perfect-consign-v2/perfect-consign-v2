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
        self::assertContains('001_tenant.sql', $tenant);
        self::assertContains('003_audit_events.sql', $tenant);
        self::assertNotContains('000_platform.sql', $tenant);
        self::assertNotContains('002_platform_jobs.sql', $tenant);
        self::assertNotContains('003_poster_jobs.sql', $tenant);
        self::assertNotContains('004_job_grants.sql', $tenant);
        self::assertNotContains('005_job_kinds.sql', $tenant);
        self::assertNotContains('009_sale_notice_kind.sql', $tenant);
        self::assertNotContains('012_job_insert.sql', $tenant);
        self::assertNotContains('013_worker_role.sql', $tenant);
        self::assertNotContains('015_override_failures.sql', $tenant);
        self::assertNotContains('017_job_mail_sent.sql', $tenant);
        self::assertNotContains('020_vendor_letter_job.sql', $tenant);
        self::assertNotContains('030_revoke_owner_failures.sql', $tenant);
        self::assertNotContains('031_job_enqueue_gate.sql', $tenant);
        self::assertNotContains('032_drop_platform_job_mail.sql', $tenant);
        self::assertNotContains('034_drop_platform_override.sql', $tenant);
        self::assertContains('011_mall_sale_span.sql', $tenant);
        self::assertContains('014_booth_deposit.sql', $tenant);
        self::assertContains('016_mail_secret.sql', $tenant);
        self::assertContains('010_competitive.sql', $tenant);
        self::assertContains('029_job_mail_sent.sql', $tenant);
        self::assertContains('033_override_lockout.sql', $tenant);
        self::assertSame(
            ['000_platform.sql', '002_platform_jobs.sql', '003_poster_jobs.sql', '004_job_grants.sql', '005_job_kinds.sql', '009_sale_notice_kind.sql', '012_job_insert.sql', '013_worker_role.sql', '015_override_failures.sql', '017_job_mail_sent.sql', '020_vendor_letter_job.sql', '024_markdown_job.sql', '025_vendor_role.sql', '030_revoke_owner_failures.sql', '031_job_enqueue_gate.sql', '035_enqueue_session_role.sql', '036_platform_admin.sql', '052_sign_in_directory.sql', '054_email_sign_in_failure_stale.sql', '055_email_sign_in_failure_address.sql'],
            $platform
        );
        self::assertSame(
            ['032_drop_platform_job_mail.sql', '034_drop_platform_override.sql'],
            $catalog->platformAfterFiles()
        );
        $checksum = $catalog->checksum('001_tenant.sql');
        self::assertSame(64, strlen($checksum));
        $mall = $catalog->read('001_tenant.sql');
        self::assertStringContainsString('color_id', $mall);
        self::assertStringContainsString('label_stock', $mall);
        self::assertStringContainsString('receipt_footer', $mall);
        self::assertStringContainsString('mall_receipt_footer_chk', $mall);
        self::assertStringContainsString("E' \\t\\n\\r'", $mall);
        $rewritten = $catalog->rewrite(
            "SELECT public.vendors FROM stall_poster WHERE search_path = 'public'",
            'tenant_0123456789abcdef0123456789abcdef',
            'poster_0123456789abcdef0123456789abcdef'
        );
        self::assertStringContainsString('tenant_0123456789abcdef0123456789abcdef.vendors', $rewritten);
        self::assertStringContainsString('poster_0123456789abcdef0123456789abcdef', $rewritten);
        self::assertStringContainsString("'tenant_0123456789abcdef0123456789abcdef'", $rewritten);
        $objects = $catalog->baselineObjects();
        self::assertContains('journal_cash_tendered', $objects['tables']);
        self::assertContains('stall_reverse_payout_run', $objects['routines']);
        self::assertContains('schema_migrations', $objects['tables']);
        $attributes = $catalog->fileObjects('001_tenant.sql');
        self::assertContains('colors', $attributes['tables']);
        self::assertContains('items_reject_disabled_attribute', $attributes['routines']);
        self::assertContains('items_disabled_attribute', $attributes['triggers']);
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
