<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

final class SqlObjects
{
    /**
     * @return array{
     *     tables: list<string>,
     *     routines: list<string>,
     *     columns: list<array{0: string, 1: string}>,
     *     triggers: list<string>,
     *     constraints: list<string>
     * }
     */
    public function from(string $sql): array
    {
        $body = $this->definitions($sql);

        return [
            'tables' => $this->unique($this->created($body, 'TABLE')),
            'routines' => $this->unique($this->created($body, 'FUNCTION')),
            'columns' => $this->columns($body),
            'triggers' => $this->unique($this->names($body, '/CREATE TRIGGER\s+([a-z_][a-z0-9_]*)/i')),
            'constraints' => $this->unique($this->names($body, '/ADD CONSTRAINT\s+([a-z_][a-z0-9_]*)/i')),
        ];
    }

    private function definitions(string $sql): string
    {
        $stripped = preg_replace('/\$([A-Za-z0-9_]*)\$(.*?)\$\1\$/s', '', $sql);

        return is_string($stripped) ? $stripped : $sql;
    }

    /**
     * @return list<string>
     */
    private function created(string $sql, string $kind): array
    {
        $pattern = $kind === 'TABLE'
            ? '/CREATE TABLE(?: IF NOT EXISTS)?\s+(?:([a-z_][a-z0-9_]*)\.)?([a-z_][a-z0-9_]*)/i'
            : '/CREATE(?: OR REPLACE)? FUNCTION\s+(?:([a-z_][a-z0-9_]*)\.)?([a-z_][a-z0-9_]*)/i';
        $found = [];
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $match) {
            $qualifier = $match[1];
            if ($qualifier !== '' && strtolower($qualifier) !== 'public') {
                continue;
            }
            $found[] = strtolower($match[2]);
        }

        return $found;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function columns(string $sql): array
    {
        $found = [];
        $table = '';
        $replaced = preg_replace_callback(
            '/ALTER TABLE\s+([a-z_][a-z0-9_.]*)|ADD COLUMN(?:\s+IF NOT EXISTS)?\s+([a-z_][a-z0-9_]*)/i',
            function (array $match) use (&$table, &$found): string {
                $alter = $match[1];
                if ($alter !== '') {
                    $table = $this->alteredTable($alter);

                    return '';
                }
                if (!isset($match[2])) {
                    return '';
                }
                $column = strtolower($match[2]);
                if ($table !== '') {
                    $found[$table . '.' . $column] = [$table, $column];
                }

                return '';
            },
            $sql
        );
        if (!is_string($replaced)) {
            return [];
        }
        $columns = array_values($found);
        usort($columns, $this->byColumn(...));

        return $columns;
    }

    private function alteredTable(string $name): string
    {
        $parts = explode('.', strtolower($name));
        $table = array_pop($parts);
        if (!is_string($table) || $table === '') {
            return '';
        }
        $qualifier = $parts[0] ?? '';
        if ($qualifier !== '' && $qualifier !== 'public') {
            return '';
        }

        return $table;
    }

    /**
     * @param array{0: string, 1: string} $left
     * @param array{0: string, 1: string} $right
     */
    private function byColumn(array $left, array $right): int
    {
        return $left[0] <=> $right[0] ?: $left[1] <=> $right[1];
    }

    /**
     * @return list<string>
     */
    private function names(string $sql, string $pattern): array
    {
        if (preg_match_all($pattern, $sql, $matches) === false) {
            return [];
        }

        return array_map(strtolower(...), $matches[1]);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function unique(array $names): array
    {
        $kept = [];
        foreach ($names as $name) {
            $kept[$name] = $name;
        }
        $values = array_values($kept);
        sort($values);

        return $values;
    }
}
