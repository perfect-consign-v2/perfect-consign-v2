<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectApp\Session\Session;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

final class SignInFailureRetentionTest extends TestCase
{
    public function testOwnerFailurePrunesStaleRowsOnInsert(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $tenantId = $this->tenantId($pdo);
        $username = 'owner';
        $retention = new SignInFailureRetention($pdo);
        try {
            $stale = $pdo->prepare(
                "INSERT INTO platform.owner_sign_in_failures (tenant_id, username, client_address, failed_at)
                 VALUES (?::uuid, ?, '10.20.0.1', clock_timestamp() - interval '20 minutes')"
            );
            $stale->execute([$tenantId, $username]);
            $retention->pruneOwner($tenantId, $username);
            $failed = $pdo->prepare(
                'INSERT INTO platform.owner_sign_in_failures (tenant_id, username, client_address)
                 VALUES (?::uuid, ?, ?)'
            );
            $failed->execute([$tenantId, $username, '10.20.0.2']);
            $count = $pdo->prepare(
                'SELECT count(*) FROM platform.owner_sign_in_failures
                 WHERE tenant_id = ?::uuid AND username = ?'
            );
            $count->execute([$tenantId, $username]);
            self::assertSame(1, (int) $count->fetchColumn());
            $ages = $pdo->prepare(
                "SELECT count(*) FROM platform.owner_sign_in_failures
                 WHERE tenant_id = ?::uuid AND username = ?
                   AND failed_at <= clock_timestamp() - interval '15 minutes'"
            );
            $ages->execute([$tenantId, $username]);
            self::assertSame(0, (int) $ages->fetchColumn());
        } finally {
            $clear = $pdo->prepare(
                'DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = ?::uuid AND username = ?'
            );
            $clear->execute([$tenantId, $username]);
        }
    }

    public function testAdminFailurePrunesStaleRowsOnInsert(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'stale-admin-' . bin2hex(random_bytes(4)) . '@example.com';
        $retention = new SignInFailureRetention($pdo);
        try {
            $stale = $pdo->prepare(
                "INSERT INTO platform.admin_sign_in_failures (email, client_address, failed_at)
                 VALUES (?, '10.21.0.1', clock_timestamp() - interval '20 minutes')"
            );
            $stale->execute([$email]);
            $retention->pruneAdmin($email);
            $failed = $pdo->prepare(
                'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
            );
            $failed->execute([$email, '10.21.0.2']);
            $count = $pdo->prepare('SELECT count(*) FROM platform.admin_sign_in_failures WHERE email = ?');
            $count->execute([$email]);
            self::assertSame(1, (int) $count->fetchColumn());
        } finally {
            $clear = $pdo->prepare('DELETE FROM platform.admin_sign_in_failures WHERE email = ?');
            $clear->execute([$email]);
        }
    }

    public function testUnknownAdminEmailDoesNotAuditRefusal(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'unknown-admin-' . bin2hex(random_bytes(4)) . '@example.com';
        $before = $this->auditCount($pdo, $email);
        $signIn = $this->adminSignIn($pdo, '10.22.0.8');
        self::assertFalse($signIn->attempt($email, 'wrong-password'));
        self::assertSame($before, $this->auditCount($pdo, $email));
        $failures = $pdo->prepare('SELECT count(*) FROM platform.admin_sign_in_failures WHERE email = ?');
        $failures->execute([$email]);
        self::assertSame(1, (int) $failures->fetchColumn());
        $clear = $pdo->prepare('DELETE FROM platform.admin_sign_in_failures WHERE email = ?');
        $clear->execute([$email]);
    }

    public function testKnownAdminWrongPasswordStillAuditsRefusal(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $email = 'known-admin-' . bin2hex(random_bytes(4)) . '@example.com';
        $hash = password_hash('correct-horse', PASSWORD_DEFAULT);
        if ($hash === false) {
            self::fail('hash failed');
        }
        $insert = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)'
        );
        $insert->execute([$email, $hash]);
        $before = $this->auditCount($pdo, $email);
        $signIn = $this->adminSignIn($pdo, '10.22.0.9');
        self::assertFalse($signIn->attempt($email, 'wrong-password'));
        self::assertSame($before + 1, $this->auditCount($pdo, $email));
        $pdo->prepare('DELETE FROM platform.admin_sign_in_failures WHERE email = ?')->execute([$email]);
        // admin_audit is append-only and references admins; leave both rows
    }

    public function testStaleIndexesExistAfterMigration(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        foreach (['owner_sign_in_failures_stale', 'admin_sign_in_failures_stale'] as $index) {
            $check = $pdo->prepare(
                "SELECT 1 FROM pg_indexes
                 WHERE schemaname = 'platform' AND indexname = ?"
            );
            $check->execute([$index]);
            self::assertSame(1, $check->rowCount(), $index . ' is missing — apply platform migrate');
        }
    }

    private function adminSignIn(PDO $pdo, string $address): AdminSignIn
    {
        $session = (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        $adminSession = new AdminSession($session);
        $request = new Request('POST', '/admin/login', [], [], '', '', $address);
        $audit = new AdminAudit($pdo, $request);

        return new AdminSignIn(
            $pdo,
            $request,
            new NullLogger(),
            $adminSession,
            $audit,
            new SignInFailureRetention($pdo),
        );
    }

    private function auditCount(PDO $pdo, string $email): int
    {
        $count = $pdo->prepare(
            "SELECT count(*) FROM platform.admin_audit
             WHERE admin_email = ? AND action = 'admin.sign_in_refused'"
        );
        $count->execute([$email]);

        return (int) $count->fetchColumn();
    }

    private function tenantId(PDO $pdo): string
    {
        $row = $pdo->prepare('SELECT id::text AS id FROM platform.tenants WHERE slug = ?');
        $row->execute([ProbeMall::SLUG]);
        $id = $row->fetchColumn();
        if (!is_string($id) || $id === '') {
            self::fail('probe mall tenant missing');
        }

        return $id;
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
