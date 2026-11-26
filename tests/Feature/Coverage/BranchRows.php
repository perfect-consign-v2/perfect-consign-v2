<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use PDOStatement;

/**
 * Rows and refusals a branch test asks the books to return.
 */
final class BranchRows
{
    public ?DatabaseRefusal $refusal = null;

    public string $refusalSql = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $bound = null;

    /** @var list<array<string, mixed>> */
    public array $listed = [];

    public int $affected = 1;
}

/**
 * Statements stand-in. SQL text selects the row or the refusal.
 */
final class BranchStatements extends Statements
{
    public function __construct(PDO $pdo, private readonly BranchRows $rows)
    {
        parent::__construct($pdo);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function bound(string $sql, array $params): array
    {
        $this->refuse($sql);
        if ($this->rows->bound !== null) {
            return $this->rows->bound;
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        return $this->rows->listed;
    }

    /**
     * @param list<mixed> $params
     */
    public function change(string $sql, array $params): void
    {
        $this->refuse($sql);
    }

    /**
     * @param list<mixed> $params
     */
    public function affected(string $sql, array $params): int
    {
        $this->refuse($sql);

        return $this->rows->affected;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(PDOStatement $statement): array
    {
        return $this->rows->listed;
    }

    public function transaction(callable $work): void
    {
        $work();
    }

    private function refuse(string $sql): void
    {
        if ($this->rows->refusal === null) {
            return;
        }
        if ($this->rows->refusalSql !== '' && !str_contains($sql, $this->rows->refusalSql)) {
            return;
        }

        throw $this->rows->refusal;
    }
}
