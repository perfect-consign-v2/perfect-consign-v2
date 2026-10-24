<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use PerfectConsign\Shared\Mail\MailboxSeal;
use RuntimeException;
use Throwable;

final class TenantMigrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SchemaCatalog $catalog,
        private readonly MigrationRecord $record,
    ) {
    }

    public function bringCurrent(string $slug): void
    {
        $this->record->holdMigration();
        try {
            $schema = $this->tenantSchema($slug);
            $this->searchPath($schema);
            if (!$this->record->ready($schema)) {
                $found = $this->pdo->query('SELECT to_regclass(' . $this->pdo->quote($schema . '.journals') . ')');
                $journals = $found === false ? false : $found->fetchColumn();
                if (is_string($journals) && $journals !== '') {
                    throw new RuntimeException('tenant ' . $slug . ' was built by an older schema; create a new database');
                }
            } else {
                $this->record->verify($schema);
            }
            $this->pendingTenant($slug, $schema);
        } finally {
            $this->record->releaseMigration();
        }
    }

    public function storeRecord(string $schema): void
    {
        $this->record->holdMigration();
        try {
            $this->searchPath($schema);
            $this->record->stamp($schema, $this->catalog->tenantFiles());
        } finally {
            $this->record->releaseMigration();
        }
    }

    private function tenantSchema(string $slug): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new RuntimeException('a tenant slug must be a short lowercase name');
        }
        $statement = $this->pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('tenant ' . $slug . ' is not provisioned');
        }

        return $schema;
    }

    private function searchPath(string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }
        $this->pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
    }

    private function pendingTenant(string $slug, string $schema): void
    {
        $applied = $this->record->ready($schema) ? $this->record->applied($schema) : [];
        $role = $this->roleName($slug, $schema);
        foreach ($this->catalog->tenantFiles() as $file) {
            if (isset($applied[$file])) {
                continue;
            }
            $this->runTenant($file, $schema, $role);
        }
    }

    private function runTenant(string $file, string $schema, string $role): void
    {
        $this->transaction(function () use ($file, $schema, $role): void {
            if ($file === '018_mail_sealed_only.sql') {
                MailboxSeal::sealPlain($this->pdo);
            }
            $this->pdo->exec($this->catalog->rewrite($this->catalog->read($file), $schema, $role));
            $this->record->stamp($schema, [$file]);
            $this->grantRead($schema, $role);
        }, $file);
    }

    /**
     * @param callable(): void $work
     */
    private function transaction(callable $work, string $file): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $work();
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($own) {
                $this->rollback();
            }
            throw new RuntimeException($file . ' was not applied: ' . $error->getMessage(), 0, $error);
        }
    }

    private function roleName(string $slug, string $schema): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new RuntimeException('a tenant slug must be a short lowercase name');
        }
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }
        if ($slug === 'mall') {
            return 'stall_poster';
        }

        return 'poster_' . substr($schema, strlen('tenant_'));
    }

    private function grantRead(string $schema, string $role): void
    {
        $this->pdo->exec('GRANT USAGE ON SCHEMA platform TO ' . $role);
        $this->pdo->exec('GRANT EXECUTE ON FUNCTION platform.latest_owner_backup(uuid) TO ' . $role);
        $this->pdo->exec('GRANT EXECUTE ON FUNCTION platform.record_owner_backup(uuid, uuid, bigint, text) TO ' . $role);
        $this->pdo->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO stall_backup');
        $this->pdo->exec('GRANT SELECT ON ALL TABLES IN SCHEMA ' . $schema . ' TO stall_backup');
        $this->pdo->exec('GRANT SELECT ON ALL SEQUENCES IN SCHEMA ' . $schema . ' TO stall_backup');
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
