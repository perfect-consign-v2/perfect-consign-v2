<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Http\Request;

/** A paged items read. Sort and page size are allowlists. Column names come from the header keys. */
final class ItemPageQuery
{
    public const int NEEDLE = 200;

    /** @var list<string> */
    public const array SORTS = [
        'code',
        'barcode',
        'description',
        'vendor',
        'brand',
        'color',
        'category',
        'size',
        'condition',
        'department',
        'intake_on',
        'expires_on',
        'status',
        'take',
        'quantity',
        'price',
        'days',
    ];

    public function __construct(
        public readonly ItemPageFilter $filter,
        public readonly string $sort,
        public readonly string $direction,
        public readonly ItemPageWindow $window,
        public readonly string $today,
    ) {
        if (!in_array($sort, self::SORTS, true) || ($direction !== 'asc' && $direction !== 'desc')) {
            throw new \InvalidArgumentException('That column cannot be sorted.');
        }
    }

    /**
     * @param list<string> $keys
     */
    public static function from(Request $request, string $stock, string $vendorId, string $today, array $keys): self
    {
        $order = self::order($request);

        return new self(
            new ItemPageFilter(
                self::clip(strtolower(trim($request->query('q')))),
                $stock,
                $vendorId,
                self::columns($request, $keys),
                self::search($request),
                $keys,
            ),
            $order['sort'],
            $order['direction'],
            ItemPageWindow::from($request),
            $today,
        );
    }

    /**
     * Stock, q, and vendor only. Used to find the delete prompt's row.
     */
    public static function scope(string $query, string $stock, string $vendorId, string $today): self
    {
        return new self(
            new ItemPageFilter(strtolower(trim($query)), $stock, $vendorId, [], '', self::SORTS),
            'code',
            'asc',
            new ItemPageWindow(0, 10, ItemPageWindow::PAGE, 0),
            $today,
        );
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private static function columns(Request $request, array $keys): array
    {
        $filters = [];
        foreach ($request->queryList('columns') as $column) {
            $picked = self::column($column, $keys);
            if ($picked === null) {
                continue;
            }
            $filters[$picked['name']] = $picked['value'];
        }

        return $filters;
    }

    /**
     * @param list<string> $keys
     * @return array{name: string, value: string}|null
     */
    private static function column(mixed $column, array $keys): ?array
    {
        if (!is_array($column)) {
            return null;
        }
        $name = $column['name'] ?? '';
        $search = $column['search'] ?? null;
        $value = is_array($search) ? ($search['value'] ?? '') : '';
        if (!is_string($name) || !is_string($value)) {
            return null;
        }
        $name = trim($name);
        $value = strtolower(trim($value));
        if ($name === '' || $value === '' || !in_array($name, $keys, true) || !in_array($name, self::SORTS, true)) {
            return null;
        }

        return ['name' => $name, 'value' => self::clip($value)];
    }

    private static function search(Request $request): string
    {
        $search = $request->queryList('search');
        $value = $search['value'] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return self::clip(strtolower(trim($value)));
    }

    /**
     * @return array{sort: string, direction: string}
     */
    private static function order(Request $request): array
    {
        $order = $request->queryList('order');
        $first = $order[0] ?? $order['0'] ?? null;
        if (!is_array($first)) {
            return ['sort' => 'code', 'direction' => 'asc'];
        }
        $direction = self::direction($first);
        $name = self::orderName($first, $request->queryList('columns'));
        if ($name === '') {
            return ['sort' => 'code', 'direction' => $direction];
        }
        if (!in_array($name, self::SORTS, true)) {
            throw new \InvalidArgumentException('That column cannot be sorted.');
        }

        return ['sort' => $name, 'direction' => $direction];
    }

    /**
     * @param array<mixed> $first
     */
    private static function direction(array $first): string
    {
        $direction = $first['dir'] ?? 'asc';
        if (!is_string($direction)) {
            throw new \InvalidArgumentException('That column cannot be sorted.');
        }
        $direction = strtolower(trim($direction));
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new \InvalidArgumentException('That column cannot be sorted.');
        }

        return $direction;
    }

    /**
     * @param array<mixed> $first
     * @param array<mixed> $columns
     */
    private static function orderName(array $first, array $columns): string
    {
        $name = $first['name'] ?? '';
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
        $index = $first['column'] ?? null;
        if (!is_string($index) && !is_int($index)) {
            return '';
        }
        $column = $columns[(int) $index] ?? null;
        if (!is_array($column)) {
            return '';
        }
        $named = $column['name'] ?? '';

        return is_string($named) ? trim($named) : '';
    }

    private static function clip(string $value): string
    {
        if (strlen($value) > self::NEEDLE) {
            throw new \InvalidArgumentException('That search is too long.');
        }

        return $value;
    }
}
