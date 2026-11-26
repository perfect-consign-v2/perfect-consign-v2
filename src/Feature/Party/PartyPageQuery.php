<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Inventory\ItemPageWindow;
use PerfectConsign\Shared\Http\Request;

/** One page of the parties list. Sort names and page sizes are allowlists. */
final class PartyPageQuery
{
    public const int NEEDLE = 200;

    /** @var list<string> */
    public const array SORTS = ['name', 'kind', 'roles'];

    /**
     * @param array<string, string> $columns
     */
    public function __construct(
        public readonly string $search,
        public readonly array $columns,
        public readonly string $sort,
        public readonly string $direction,
        public readonly ItemPageWindow $window,
    ) {
        if (!in_array($sort, self::SORTS, true) || ($direction !== 'asc' && $direction !== 'desc')) {
            throw new \InvalidArgumentException('That column cannot be sorted.');
        }
    }

    public static function from(Request $request): self
    {
        $columns = $request->queryList('columns');
        $order = self::order($request->queryList('order'), $columns);

        return new self(
            self::needle($request->queryList('search')['value'] ?? ''),
            self::filters($columns),
            $order['sort'],
            $order['direction'],
            ItemPageWindow::from($request),
        );
    }

    /**
     * @param array<mixed> $columns
     * @return array<string, string>
     */
    private static function filters(array $columns): array
    {
        $filters = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $name = self::name($column);
            $search = $column['search'] ?? null;
            $value = self::needle(is_array($search) ? ($search['value'] ?? '') : '');
            if ($value !== '' && in_array($name, self::SORTS, true)) {
                $filters[$name] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param array<mixed> $order
     * @param array<mixed> $columns
     * @return array{sort: string, direction: string}
     */
    private static function order(array $order, array $columns): array
    {
        $first = $order[0] ?? null;
        if (!is_array($first)) {
            return ['sort' => 'name', 'direction' => 'asc'];
        }
        $direction = $first['dir'] ?? 'asc';
        $direction = is_string($direction) ? strtolower(trim($direction)) : '';
        $index = $first['column'] ?? null;
        $column = is_string($index) || is_int($index) ? ($columns[(int) $index] ?? null) : null;
        $sort = is_array($column) ? self::name($column) : '';

        return ['sort' => $sort === '' ? 'name' : $sort, 'direction' => $direction];
    }

    /**
     * @param array<mixed> $column
     */
    private static function name(array $column): string
    {
        $name = $column['name'] ?? '';

        return is_string($name) ? trim($name) : '';
    }

    private static function needle(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = strtolower(trim($value));
        if (strlen($value) > self::NEEDLE) {
            throw new \InvalidArgumentException('That search is too long.');
        }

        return $value;
    }
}
