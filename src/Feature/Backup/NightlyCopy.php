<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;

final class NightlyCopy
{
    public function headline(string $schema): string
    {
        $latest = $this->latest($schema);
        if ($latest === null) {
            return 'No nightly copy is on this server yet. To put a mall back, an operator restores that zip. This page does not restore.';
        }

        return 'Last nightly copy: ' . $latest->format('F j, Y')
            . '. To put a mall back, an operator restores that zip. This page does not restore.';
    }

    private function latest(string $schema): ?DateTimeImmutable
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return null;
        }
        $root = getenv('STALL_BACKUP_DIR');
        if (!is_string($root) || $root === '' || str_contains($root, 'perfect_consign_pg') || !is_dir($root)) {
            return null;
        }
        $volume = realpath($root);
        if ($volume === false || str_contains($volume, 'perfect_consign_pg')) {
            return null;
        }
        $dir = $volume . DIRECTORY_SEPARATOR . $schema;
        if (!is_dir($dir)) {
            return null;
        }
        $names = scandir($dir);
        if ($names === false) {
            return null;
        }
        $newest = null;
        foreach ($names as $name) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.zip$/', $name, $match) !== 1) {
                continue;
            }
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new DateTimeZone('UTC'));
            if ($day === false) {
                continue;
            }
            if ($newest === null || $day > $newest) {
                $newest = $day;
            }
        }

        return $newest;
    }
}
