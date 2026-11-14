<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * SQLite PDO whose query and prepare results are chosen by SQL fragments.
 * exec() is a no-op so Postgres text can be "applied" without a server.
 */
final class ScriptPdo extends PDO
{
    public bool $throwOnBegin = false;

    /** @var list<array{match: string, rows: list<array<string, mixed>>|false}> */
    private array $queries = [];

    /** @var list<array{match: string, rows: list<array<string, mixed>>|false}> */
    private array $prepares = [];

    /** @var list<array{match: string, rows: list<array<string, mixed>>|false}> */
    private array $onceQueries = [];

    /** @var array<string, list<list<array<string, mixed>>|false>> */
    private array $queryQueues = [];

    /** @var array<string, list<list<array<string, mixed>>|false>> */
    private array $prepareQueues = [];

    /** @var list<array{match: string, effect: 'throw'|'false'}> */
    private array $oncePrepares = [];

    private int $sequence = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
        $this->setAttribute(self::ATTR_DEFAULT_FETCH_MODE, self::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>>|false $rows
     */
    public function onQuery(string $match, array|false $rows): void
    {
        $this->queries[] = ['match' => $match, 'rows' => $rows];
    }

    /**
     * @param list<array<string, mixed>>|false $rows
     */
    public function onceQuery(string $match, array|false $rows): void
    {
        $this->onceQueries[] = ['match' => $match, 'rows' => $rows];
    }

    /**
     * @param list<array<string, mixed>>|false $rows
     */
    public function pushQuery(string $match, array|false $rows): void
    {
        $this->queryQueues[$match][] = $rows;
    }

    /**
     * @param list<array<string, mixed>>|false $rows
     */
    public function pushPrepare(string $match, array|false $rows): void
    {
        $this->prepareQueues[$match][] = $rows;
    }

    /**
     * @param list<array<string, mixed>>|false $rows
     */
    public function onPrepare(string $match, array|false $rows): void
    {
        $this->prepares[] = ['match' => $match, 'rows' => $rows];
    }

    public function oncePrepareThrows(string $match): void
    {
        $this->oncePrepares[] = ['match' => $match, 'effect' => 'throw'];
    }

    public function beginTransaction(): bool
    {
        if ($this->throwOnBegin) {
            throw new RuntimeException('begin failed');
        }

        return parent::beginTransaction();
    }

    public function exec(string $statement): int
    {
        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        unset($fetchMode, $fetchModeArgs);
        $queued = $this->shiftQueue($this->queryQueues, $query);
        if ($queued !== null) {
            if ($queued === false) {
                return false;
            }

            return $this->select($queued);
        }
        foreach ($this->onceQueries as $index => $rule) {
            if (!str_contains($query, $rule['match'])) {
                continue;
            }
            array_splice($this->onceQueries, $index, 1);
            if ($rule['rows'] === false) {
                return false;
            }

            return $this->select($rule['rows']);
        }
        foreach ($this->queries as $rule) {
            if (!str_contains($query, $rule['match'])) {
                continue;
            }
            if ($rule['rows'] === false) {
                return false;
            }

            return $this->select($rule['rows']);
        }

        return $this->select([]);
    }

    /**
     * @param array<int|string, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        unset($options);
        $queued = $this->shiftQueue($this->prepareQueues, $query);
        if ($queued !== null) {
            if ($queued === false) {
                return false;
            }

            return $this->prepared($queued, $this->placeholders($query));
        }
        foreach ($this->oncePrepares as $index => $rule) {
            if (!str_contains($query, $rule['match'])) {
                continue;
            }
            array_splice($this->oncePrepares, $index, 1);
            if ($rule['effect'] === 'throw') {
                throw new RuntimeException('prepared statement failed');
            }

            return false;
        }
        foreach ($this->prepares as $rule) {
            if (!str_contains($query, $rule['match'])) {
                continue;
            }
            if ($rule['rows'] === false) {
                return false;
            }

            return $this->prepared($rule['rows'], $this->placeholders($query));
        }

        return $this->prepared([], $this->placeholders($query));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function select(array $rows): PDOStatement
    {
        $table = $this->fill($rows);
        $statement = parent::query('SELECT ' . $this->columnList($rows) . ' FROM ' . $table);
        if ($statement === false) {
            throw new RuntimeException('scripted select failed');
        }

        return $statement;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function prepared(array $rows, int $placeholders): PDOStatement
    {
        $table = $this->fill($rows);
        $where = '';
        if ($placeholders > 0) {
            $parts = [];
            for ($index = 0; $index < $placeholders; $index++) {
                $parts[] = '(CAST(? AS TEXT) IS NULL OR 1 = 1)';
            }
            $where = ' WHERE ' . implode(' AND ', $parts);
        }
        $statement = parent::prepare('SELECT ' . $this->columnList($rows) . ' FROM ' . $table . $where);
        if ($statement === false) {
            throw new RuntimeException('scripted prepare failed');
        }

        return $statement;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fill(array $rows): string
    {
        $this->sequence++;
        $table = 's' . $this->sequence;
        $columns = $rows === [] ? ['c'] : array_keys($rows[0]);
        $definitions = [];
        foreach ($columns as $column) {
            $definitions[] = $this->quoteColumn($column) . ' TEXT';
        }
        $created = parent::exec('CREATE TABLE ' . $table . ' (' . implode(', ', $definitions) . ')');
        if ($created === false) {
            throw new RuntimeException('scripted table failed');
        }
        if ($rows === []) {
            return $table;
        }
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = $this->quoteColumn($column);
        }
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $insert = parent::prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ') VALUES (' . $marks . ')'
        );
        if ($insert === false) {
            throw new RuntimeException('scripted insert failed');
        }
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->cell($row[$column] ?? null);
            }
            $insert->execute($values);
        }

        return $table;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function columnList(array $rows): string
    {
        $columns = $rows === [] ? ['c'] : array_keys($rows[0]);
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = $this->quoteColumn($column);
        }

        return implode(', ', $quoted);
    }

    private function quoteColumn(string $column): string
    {
        return '"' . str_replace('"', '""', $column) . '"';
    }

    private function cell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, list<list<array<string, mixed>>|false>> $queues
     * @return list<array<string, mixed>>|false|null
     */
    private function shiftQueue(array &$queues, string $query): array|false|null
    {
        foreach ($queues as $match => $queue) {
            if ($queue === [] || !str_contains($query, $match)) {
                continue;
            }
            $rows = array_shift($queues[$match]);

            return $rows ?? null;
        }

        return null;
    }

    private function placeholders(string $query): int
    {
        $count = preg_match_all('/\?/', $query);

        return $count === false ? 0 : $count;
    }
}
