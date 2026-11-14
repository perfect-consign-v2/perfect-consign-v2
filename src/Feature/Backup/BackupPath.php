<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class BackupPath
{
    public static function contains(string $root, string $path): bool
    {
        $real = realpath($path);
        $prefix = $root . DIRECTORY_SEPARATOR;

        return $real !== false && str_starts_with($real, $prefix) && !is_link($path);
    }
}
