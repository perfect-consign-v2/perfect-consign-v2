<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Vendor;

use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PHPUnit\Framework\TestCase;

final class VendorEntryRoleTest extends TestCase
{
    public function testVendorEntryRoleCannotInsertJournals(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->appPdo();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->exec('SET ROLE stall_vendor_entry');
        try {
            $insert = $pdo->query("SELECT has_table_privilege('journals', 'INSERT')")->fetchColumn();
            self::assertFalse((bool) $insert);
            $select = $pdo->query("SELECT has_table_privilege('vendor_entry_requests', 'INSERT')")->fetchColumn();
            self::assertTrue((bool) $select);
        } finally {
            $pdo->exec('RESET ROLE');
        }
    }

    public function testVendorRoleStillSelectsPortalViews(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->appPdo();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->exec('SET ROLE stall_vendor');
        try {
            $count = $pdo->query('SELECT count(*)::int FROM stall_vendor_items')->fetchColumn();
            self::assertNotFalse($count);
            $journals = $pdo->query("SELECT has_table_privilege('journals', 'INSERT')")->fetchColumn();
            self::assertFalse((bool) $journals);
        } finally {
            $pdo->exec('RESET ROLE');
        }
    }

    private function schema(PDO $pdo): string
    {
        $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([ProbeMall::SLUG]);
        $schema = $statement->fetchColumn();
        self::assertIsString($schema);
        self::assertMatchesRegularExpression('/^tenant_[0-9a-f]{32}$/', $schema);

        return $schema;
    }

    private function appPdo(): PDO
    {
        $password = getenv('STALL_APP_PASSWORD');
        if (!is_string($password) || $password === '') {
            self::fail('STALL_APP_PASSWORD is not set');
        }

        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            'stall_app',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is not set');
        }

        return $value;
    }
}
