<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use InvalidArgumentException;
use RuntimeException;

final class SchemaCatalog
{
    /** @var list<string> */
    public const PLATFORM = [
        '000_platform.sql',
        '015_stall_app.sql',
        '016_owners.sql',
        '018_owner_backup.sql',
        '047_platform_schema_migrations.sql',
        '057_owner_recovery.sql',
        '060_store_name.sql',
        '064_sign_in_address.sql',
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<string>
     */
    public function tenantFiles(): array
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '0*.sql');
        if ($paths === false) {
            throw new RuntimeException('could not list schema files');
        }
        $files = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (in_array($name, self::PLATFORM, true)) {
                continue;
            }
            $files[] = $name;
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    public function platformFiles(): array
    {
        return self::PLATFORM;
    }

    /**
     * @return array{tables: list<string>, routines: list<string>}
     */
    public function baselineObjects(): array
    {
        $tables = [];
        $routines = [];
        foreach ($this->tenantFiles() as $file) {
            if ($file >= '044_schema_migrations.sql') {
                continue;
            }
            $objects = $this->fileObjects($file);
            foreach ($objects['tables'] as $name) {
                $tables[$name] = $name;
            }
            foreach ($objects['routines'] as $name) {
                $routines[$name] = $name;
            }
        }
        $tableNames = array_values($tables);
        $routineNames = array_values($routines);
        sort($tableNames);
        sort($routineNames);

        return ['tables' => $tableNames, 'routines' => $routineNames];
    }

    /**
     * @return array{
     *     tables: list<string>,
     *     routines: list<string>,
     *     columns: list<array{0: string, 1: string}>,
     *     triggers: list<string>,
     *     constraints: list<string>
     * }
     */
    public function fileObjects(string $file): array
    {
        return (new SqlObjects())->from($this->read($file));
    }

    public function checksum(string $file): string
    {
        return hash('sha256', $this->read($file));
    }

    public function read(string $file): string
    {
        if (preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', $file) !== 1) {
            throw new InvalidArgumentException('schema file name is invalid');
        }
        $bytes = file_get_contents($this->directory . DIRECTORY_SEPARATOR . $file);
        if ($bytes === false) {
            throw new RuntimeException('could not read ' . $file);
        }

        return $bytes;
    }

    public function rewrite(string $sql, string $schema, string $role): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new InvalidArgumentException('schema name is not a tenant schema');
        }
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new InvalidArgumentException('poster role name is invalid');
        }

        return str_replace(
            ['public.', 'SCHEMA public', 'stall_poster'],
            [$schema . '.', 'SCHEMA ' . $schema, $role],
            $sql
        );
    }
}
