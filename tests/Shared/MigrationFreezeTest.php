<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

final class MigrationFreezeTest extends TestCase
{
    public function testAStampedFileNumberIsRefused(): void
    {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'check-migration-freeze.php';
        $failures = migrationFreezeFailures(1, ['sql/001_tenant.sql', 'sql/003_audit_events.sql']);
        self::assertSame(['sql/001_tenant.sql is at or below 001'], $failures);
        self::assertSame([], migrationFreezeFailures(1, ['sql/003_audit_events.sql']));
    }

    public function testABaselineResetSkipsFreeze(): void
    {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'check-migration-freeze.php';
        self::assertSame(
            [],
            migrationFreezeFailures(55, ['sql/001_tenant.sql', 'sql/003_audit_events.sql'], true)
        );
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-baseline-' . bin2hex(random_bytes(4));
        $sql = $root . DIRECTORY_SEPARATOR . 'sql';
        if (!mkdir($sql, 0700, true) && !is_dir($sql)) {
            self::fail('baseline fixture could not be created');
        }
        try {
            self::assertNotFalse(file_put_contents($sql . DIRECTORY_SEPARATOR . '000_platform.sql', "--\n"));
            self::assertNotFalse(file_put_contents($sql . DIRECTORY_SEPARATOR . '001_tenant.sql', "--\n"));
            self::assertTrue(migrationBaselineReset($root));
        } finally {
            foreach (glob($sql . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($sql);
            rmdir($root);
        }
        self::assertFalse(migrationBaselineReset(dirname(__DIR__, 2)));
    }
}
