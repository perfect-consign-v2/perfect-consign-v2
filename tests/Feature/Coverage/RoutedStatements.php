<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Shared\Database\Statements;
use PDO;
use PDOStatement;
use Throwable;

class RoutedStatements extends Statements
{
    /** @var list<array{match: string, rows: list<array<string, mixed>>}> */
    public array $rowRoutes = [];

    /** @var list<array{match: string, rows: list<array<string, mixed>>}> */
    public array $boundRoutes = [];

    public int $affectedCount = 1;

    public ?Throwable $affectedError = null;

    public ?Throwable $changeError = null;

    public string $changeErrorSql = '';

    public ?Throwable $boundError = null;

    public string $boundErrorSql = '';

    public ?Throwable $isolatedError = null;

    public ?Throwable $transactionError = null;

    /** @var list<array<string, mixed>> */
    public array $movement = [];

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @param list<mixed> $params
     */
    public function change(string $sql, array $params): void
    {
        if ($this->changeError !== null && ($this->changeErrorSql === '' || str_contains($sql, $this->changeErrorSql))) {
            throw $this->changeError;
        }
    }

    /**
     * @param list<mixed> $params
     */
    public function affected(string $sql, array $params): int
    {
        if ($this->affectedError !== null) {
            throw $this->affectedError;
        }

        return $this->affectedCount;
    }

    public function transaction(callable $work): void
    {
        if ($this->transactionError !== null) {
            throw $this->transactionError;
        }
        $work();
    }

    public function isolated(callable $work): void
    {
        if ($this->isolatedError !== null) {
            throw $this->isolatedError;
        }
        $work();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        foreach ($this->rowRoutes as $route) {
            if (str_contains($sql, $route['match'])) {
                return $route['rows'];
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function bound(string $sql, array $params): array
    {
        if ($this->boundError !== null && ($this->boundErrorSql === '' || str_contains($sql, $this->boundErrorSql))) {
            throw $this->boundError;
        }
        foreach ($this->boundRoutes as $route) {
            if (str_contains($sql, $route['match'])) {
                return $route['rows'];
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(PDOStatement $statement): array
    {
        unset($statement);

        return $this->movement;
    }
}
