<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use PDOException;
use RuntimeException;

final class MigrationLock
{
    private const LOCK = 814202615;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hold(): void
    {
        $query = $this->pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK . ')');
        $value = $query === false ? false : $query->fetchColumn();
        if ($value !== true && $value !== 't' && $value !== 1 && $value !== '1') {
            throw new RuntimeException('a migration is already running');
        }
    }

    public function release(): void
    {
        $this->clearAbortedTransaction();
        $query = $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK . ')');
        if ($query === false) {
            throw new RuntimeException('the migration lock could not be released');
        }
    }

    private function clearAbortedTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }
        try {
            $probe = $this->pdo->query('SELECT 1');
            if ($probe === false) {
                $this->pdo->rollBack();
            }
        } catch (PDOException) {
            $this->pdo->rollBack();
        }
    }
}
