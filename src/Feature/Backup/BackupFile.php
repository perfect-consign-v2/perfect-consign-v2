<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class BackupFile
{
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $directory,
        public readonly int $byteSize,
        public readonly string $sha256,
        public readonly string $downloadName,
        public readonly string $takenAt,
    ) {
    }
}
