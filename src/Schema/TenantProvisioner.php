<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class TenantProvisioner
{
    /** Store addresses the platform keeps for its own pages. */
    public const RESERVED = ['admin', 'forgot', 'signup', 'assets'];

    /**
     * @return list<string>
     */
    public static function ledgerFiles(): array
    {
        return (new SchemaCatalog(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql'
        ))->tenantFiles();
    }

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $sqlDirectory,
    ) {
    }

    /**
     * Builds the whole store in one transaction: the poster role, the tenant row,
     * the schema and its installer, and every grant. Nothing is left behind when
     * any step fails. $before runs inside that transaction, just before commit,
     * with the tenant id, schema, and poster role.
     *
     * @param null|\Closure(string, string, string): void $before
     */
    public function provision(string $slug, string $displayName, ?\Closure $before = null): string
    {
        $this->assertSlug($slug);
        if ($displayName !== trim($displayName) || $displayName === '') {
            throw new InvalidArgumentException('a tenant display name is required');
        }
        $this->assertBackupRole();
        $id = $this->newTenantId();
        $schema = 'tenant_' . str_replace('-', '', $id);
        $this->assertSchema($schema);
        $role = $this->roleName($slug, $schema);
        $this->pdo->beginTransaction();
        try {
            $this->ensurePosterRole($role);
            $this->pdo->exec(sprintf('GRANT %s TO CURRENT_USER', $role));
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.tenants (id, slug, schema_name, display_name) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$id, $slug, $schema, $displayName]);
            $this->pdo->exec(sprintf('CREATE SCHEMA %s', $schema));
            $this->pdo->exec(sprintf('REVOKE ALL ON SCHEMA %s FROM PUBLIC', $schema));
            $this->applyLedger($schema, $role);
            $this->grantApp($role);
            $this->grantBackup($schema, $role);
            $check = $this->pdo->prepare('SELECT platform.assert_poster_grants(?)');
            $check->execute([$role]);
            if ($before !== null) {
                $before($id, $schema, $role);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $schema;
    }

    private function newTenantId(): string
    {
        $idStatement = $this->pdo->query('SELECT uuidv7() AS id');
        if ($idStatement === false) {
            throw new RuntimeException('could not create a tenant id');
        }
        $idRow = $idStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($idRow) || !is_string($idRow['id'] ?? null)) {
            throw new RuntimeException('could not create a tenant id');
        }

        return $idRow['id'];
    }

    private function ensurePosterRole(string $role): void
    {
        if ($this->roleExists($role)) {
            return;
        }
        $create = $this->pdo->prepare('SELECT platform.create_poster(?)');
        $create->execute([$role]);
    }

    public function applyLedger(string $schema, string $role): void
    {
        $this->assertSchema($schema);
        $this->assertRole($role);
        $this->pdo->exec(sprintf('SET search_path TO %s, pg_catalog, extensions', $schema));
        foreach (self::ledgerFiles() as $file) {
            $path = $this->sqlDirectory . DIRECTORY_SEPARATOR . $file;
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('could not read ' . $file);
            }
            $this->pdo->exec($this->rewrite($sql, $schema, $role));
        }
        (new SchemaMigrator(
            $this->pdo,
            new SchemaCatalog($this->sqlDirectory)
        ))->recordTenant($schema);
    }

    public function rewrite(string $sql, string $schema, string $role): string
    {
        return (new SchemaCatalog($this->sqlDirectory))->rewrite($sql, $schema, $role);
    }

    public function grantApp(string $role): void
    {
        $this->assertRole($role);
        $exists = $this->pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_app'");
        if ($exists === false || $exists->fetchColumn() === false) {
            throw new RuntimeException('stall_app is missing. Apply sql/000_platform.sql first.');
        }
        $this->pdo->exec(sprintf('GRANT %s TO stall_app', $role));
        $worker = $this->pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_worker'");
        if ($worker !== false && $worker->fetchColumn() !== false) {
            $this->pdo->exec(sprintf('GRANT %s TO stall_worker', $role));
        }
        $platform = $this->pdo->prepare('SELECT platform.grant_poster_platform(?)');
        $platform->execute([$role]);
    }

    public function grantBackup(string $schema, string $role): void
    {
        $this->assertSchema($schema);
        $this->assertRole($role);
        $this->assertBackupRole();
        $this->pdo->exec(sprintf('GRANT USAGE ON SCHEMA %s TO stall_backup', $schema));
        $this->pdo->exec(sprintf('GRANT SELECT ON ALL TABLES IN SCHEMA %s TO stall_backup', $schema));
        $this->pdo->exec(sprintf('GRANT SELECT ON ALL SEQUENCES IN SCHEMA %s TO stall_backup', $schema));
    }

    public function roleName(string $slug, string $schema): string
    {
        $this->assertSlug($slug);
        $this->assertSchema($schema);
        if ($slug === 'mall') {
            return 'stall_poster';
        }

        return 'poster_' . substr($schema, strlen('tenant_'));
    }

    private function assertSlug(string $slug): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new InvalidArgumentException('a tenant slug must be a short lowercase name');
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw new InvalidArgumentException($slug . ' is reserved for the platform');
        }
    }

    private function assertSchema(string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new InvalidArgumentException('schema name is not a tenant schema');
        }
    }

    private function roleExists(string $role): bool
    {
        $this->assertRole($role);
        $exists = $this->pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $exists->execute([$role]);

        return $exists->fetchColumn() !== false;
    }

    private function assertRole(string $role): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new InvalidArgumentException('poster role name is invalid');
        }
    }

    private function assertBackupRole(): void
    {
        $exists = $this->pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_backup'");
        if ($exists === false || $exists->fetchColumn() === false) {
            throw new RuntimeException('stall_backup is missing. Apply sql/000_platform.sql first.');
        }
    }
}
