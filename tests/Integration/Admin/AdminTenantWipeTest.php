<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Admin;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminTenantWipe;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminTenantWipeTest extends TestCase
{
    public function testOneAdminAloneIsRefusedWithoutOkAudit(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $tenant = $this->probeTenant($pdo);
        $acting = $this->plantAdmin($pdo, 'wipe-acting-' . bin2hex(random_bytes(3)) . '@example.test', 'WipeActingPass1!');
        $wipe = $this->wipe($pdo);
        try {
            $wipe->wipe($acting, $tenant, $tenant->slug, '', '');
            self::fail('wipe without a second admin was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('second platform admin', $error->getMessage());
        }
        self::assertSame(0, $this->okDeletes($pdo, $tenant->id, $acting['email']));
        self::assertGreaterThan(0, $this->refusedDeletes($pdo, $tenant->id, $acting['email'], 'second_admin'));
    }

    public function testMissingOwnerBackupIsRefused(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->pdo();
        $slug = 'wipebak' . bin2hex(random_bytes(2));
        $tenant = $this->provisionDisposable($root, $pdo, $slug);
        $acting = $this->plantAdmin($pdo, 'wipe-a-' . bin2hex(random_bytes(3)) . '@example.test', 'WipeActingPass1!');
        $second = $this->plantAdmin($pdo, 'wipe-b-' . bin2hex(random_bytes(3)) . '@example.test', 'WipeSecondPass1!');
        $wipe = $this->wipe($pdo);
        try {
            try {
                $wipe->wipe($acting, $tenant, $tenant->slug, $second['email'], 'WipeSecondPass1!');
                self::fail('wipe without a recent owner backup was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('seven days', $error->getMessage());
            }
            self::assertSame(0, $this->okDeletes($pdo, $tenant->id, $acting['email']));
            self::assertGreaterThan(0, $this->refusedDeletes($pdo, $tenant->id, $acting['email'], 'backup_required'));
        } finally {
            $this->dropDisposable($root, $pdo, $slug);
        }
    }

    /**
     * @return array{id: string, email: string, version: string}
     */
    private function plantAdmin(PDO $pdo, string $email, string $password): array
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash
             RETURNING id::text AS id, email, credentials_version::text AS version'
        );
        $statement->execute([$email, $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'version' => (string) $row['version'],
        ];
    }

    private function wipe(PDO $pdo): AdminTenantWipe
    {
        return new AdminTenantWipe(
            $pdo,
            new AdminAudit($pdo, new Request('POST', '/admin/tenants/wipe', [], [], '', 'https://example.test', '127.0.0.1')),
            new BackupStore($pdo),
        );
    }

    private function probeTenant(PDO $pdo): TenantRecord
    {
        return $this->tenantBySlug($pdo, ProbeMall::SLUG);
    }

    private function provisionDisposable(string $root, PDO $pdo, string $slug): TenantRecord
    {
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'provision-tenant.php';
        $provisioner = new \TenantProvisioner($pdo, $root . DIRECTORY_SEPARATOR . 'sql');
        $schema = $provisioner->provision($slug, 'Wipe Backup Probe');

        return new TenantRecord(
            $this->tenantId($pdo, $slug),
            $slug,
            $schema,
            TenantRecord::roleFor($slug, $schema),
            'Wipe Backup Probe',
        );
    }

    private function dropDisposable(string $root, PDO $pdo, string $slug): void
    {
        $found = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $found->execute([$slug]);
        if ($found->fetchColumn() === false) {
            return;
        }
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
        wipeTenant($pdo, $slug, $slug);
    }

    private function tenantBySlug(PDO $pdo, string $slug): TenantRecord
    {
        $statement = $pdo->prepare(
            'SELECT id::text AS id, slug, schema_name, display_name
             FROM platform.tenants
             WHERE slug = ?'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return new TenantRecord(
            (string) $row['id'],
            (string) $row['slug'],
            (string) $row['schema_name'],
            'stall_poster',
            (string) $row['display_name'],
        );
    }

    private function tenantId(PDO $pdo, string $slug): string
    {
        $statement = $pdo->prepare('SELECT id::text FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);
        $id = $statement->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    private function okDeletes(PDO $pdo, string $tenantId, string $adminEmail): int
    {
        $statement = $pdo->prepare(
            "SELECT count(*)::int
             FROM platform.admin_audit
             WHERE tenant_id = ?::uuid
               AND lower(admin_email) = lower(?)
               AND action = 'tenant.delete'
               AND outcome = 'ok'"
        );
        $statement->execute([$tenantId, $adminEmail]);

        return (int) $statement->fetchColumn();
    }

    private function refusedDeletes(PDO $pdo, string $tenantId, string $adminEmail, string $reason): int
    {
        $statement = $pdo->prepare(
            "SELECT count(*)::int
             FROM platform.admin_audit
             WHERE tenant_id = ?::uuid
               AND lower(admin_email) = lower(?)
               AND action = 'tenant.delete_refused'
               AND outcome = 'refused'
               AND detail->>'reason' = ?"
        );
        $statement->execute([$tenantId, $adminEmail, $reason]);

        return (int) $statement->fetchColumn();
    }

    private function pdo(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
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
