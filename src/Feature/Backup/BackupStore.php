<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use PDO;
use PDOException;
use PerfectConsign\Ledger\DatabaseRefusal;

final class BackupStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function latest(string $tenantId): ?DateTimeImmutable
    {
        try {
            $statement = $this->pdo->prepare('SELECT platform.latest_owner_backup(?::uuid)');
            $statement->execute([$tenantId]);
            $value = $statement->fetchColumn();
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
        if ($value === false || $value === null) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }

    public function record(string $tenantId, string $backupId, int $byteSize, string $sha256): void
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT platform.record_owner_backup(?::uuid, ?::uuid, ?::bigint, ?)'
            );
            $statement->execute([$tenantId, $backupId, $byteSize, $sha256]);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
    }
}
