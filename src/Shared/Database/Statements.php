<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Database;

use PDO;
use PDOException;
use PDOStatement;
use PerfectConsign\Ledger\DatabaseRefusal;

final class Statements
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<mixed> $params
     */
    public function change(string $sql, array $params): void
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return [];
        }

        return $this->all($statement);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(PDOStatement $statement): array
    {
        $rows = [];
        foreach ($statement as $row) {
            $keyed = $this->assoc($row);
            if ($keyed !== null) {
                $rows[] = $keyed;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function assoc(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $keyed = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $keyed[$key] = $value;
        }

        return $keyed;
    }
}
