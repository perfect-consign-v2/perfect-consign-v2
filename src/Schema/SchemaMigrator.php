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
        if (!$this->record->ready('platform')) {
            if ($this->installed()) {
                $this->finishPlatform();
            } else {
                $this->installPlatform();
            }
        }
        $this->record->verify('platform');
        $this->pendingPlatform();
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

    private function finishPlatform(): void
    {
        $this->transaction(function (): void {
            $this->pdo->exec($this->catalog->read('047_platform_schema_migrations.sql'));
            $this->record->stamp('platform', $this->platformBaseline());
        }, '047_platform_schema_migrations.sql');
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

    /**
     * @return list<string>
     */
    private function platformBaseline(): array
    {
        $files = [];
        foreach ($this->catalog->platformFiles() as $file) {
            if ($file > '047_platform_schema_migrations.sql') {
                continue;
            }
            $files[] = $file;
        }

        return $files;
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
