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
}
