<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use RuntimeException;
use Throwable;

final class SchemaMigrator
{
    private readonly MigrationRecord $record;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SchemaCatalog $catalog,
    ) {
        $this->record = new MigrationRecord($pdo, $catalog);
    }

    public function migratePlatform(): void
    {
        $this->record->holdMigration();
        try {
            if (!$this->record->ready('platform')) {
                if ($this->installed()) {
                    throw new RuntimeException('this database was built by an older schema; create a new database');
                }
                $this->installPlatform();
            }
            $this->record->verify('platform');
            $this->pendingPlatform();
        } finally {
            $this->record->releaseMigration();
        }
    }

    public function migratePlatformAfter(): void
    {
        $this->record->holdMigration();
        try {
            if (!$this->record->ready('platform')) {
                throw new RuntimeException('platform is not ready');
            }
            $applied = $this->record->applied('platform');
            foreach ($this->catalog->platformAfterFiles() as $file) {
                if (isset($applied[$file])) {
                    continue;
                }
                $this->runPlatform($file);
            }
        } finally {
            $this->record->releaseMigration();
        }
    }

    public function migrateTenant(string $slug): void
    {
        $this->tenants()->bringCurrent($slug);
    }

    public function recordTenant(string $schema): void
    {
        $this->tenants()->storeRecord($schema);
    }

    private function tenants(): TenantMigrator
    {
        return new TenantMigrator($this->pdo, $this->catalog, $this->record);
    }

    private function installed(): bool
    {
        $query = $this->pdo->query(
            "SELECT 1 FROM pg_event_trigger WHERE evtname = 'platform_reject_guard_function_replace'"
        );

        return $query !== false && $query->fetchColumn() !== false;
    }

    private function installPlatform(): void
    {
        $applied = [];
        foreach ($this->catalog->platformFiles() as $file) {
            $applied[] = $file;
            $this->transaction(function () use ($file, &$applied): void {
                $this->pdo->exec($this->catalog->read($file));
                if ($this->record->ready('platform')) {
                    $this->record->stamp('platform', $applied);
                }
            }, $file);
        }
    }

    private function pendingPlatform(): void
    {
        $applied = $this->record->applied('platform');
        foreach ($this->catalog->platformFiles() as $file) {
            if (isset($applied[$file])) {
                continue;
            }
            $this->runPlatform($file);
        }
    }

    private function runPlatform(string $file): void
    {
        $this->transaction(function () use ($file): void {
            $this->pdo->exec($this->catalog->read($file));
            $this->record->stamp('platform', [$file]);
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

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
