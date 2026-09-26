<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class SchemaDump
{
    public static function rewrite(string $sql, string $from, string $to): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $from) !== 1
            || preg_match('/^tenant_[0-9a-f]{32}$/', $to) !== 1
        ) {
            throw new BackupException('schema name is not a tenant schema');
        }
        if (!str_contains($sql, $from)) {
            throw new BackupException('the dump does not name its schema');
        }

        return str_replace($from, $to, $sql);
    }
}
