<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/** Allowlisted page, counts, and the row index of one id. SQL fragments never come from the request. */
final class ItemPageRead
{
    /** @var array<string, string> */
    private const array ORDER = [
        'code' => 'lower(item.code) %s, item.code %s',
        'barcode' => 'lower(item.barcode) %s NULLS LAST, item.barcode %s NULLS LAST',
        'description' => 'lower(item.description) %s, item.description %s',
        'vendor' => 'lower(vendor.code) %s NULLS LAST, lower(vendor.name) %s NULLS LAST',
        'brand' => 'lower(brand.name) %s NULLS LAST',
        'color' => 'lower(color.name) %s NULLS LAST',
        'category' => 'lower(category.name) %s NULLS LAST',
        'size' => 'lower(size.name) %s NULLS LAST',
        'condition' => 'lower(condition.name) %s NULLS LAST',
        'department' => 'lower(department.name) %s NULLS LAST',
        'intake_on' => 'item.intake_on %s NULLS LAST',
        'expires_on' => 'item.expires_on %s NULLS LAST',
        'take' => 'COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps) %s',
        'quantity' => 'item.quantity %s',
        'price' => 'item.price_minor %s',
    ];

    public function __construct(private readonly Statements $statements)
    {
    }

    public function page(ItemPageQuery $query): ItemPage
    {
        $total = $this->count($query, 'total');
        $filtered = $this->count($query, 'filtered');
        $start = $query->window->mode === ItemPageWindow::ALL
            ? 0
            : $this->clamp($query->window->start, $query->window->length, $filtered);

        return new ItemPage($this->slice($query, $start), $total, $filtered, $start);
    }

    public function place(ItemPageQuery $query, string $id): int
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            return -1;
        }
        [$where, $params] = $this->where($query, 'filtered');
        [$order, $orderParams] = $this->order($query);
        $sql = 'SELECT (ranked.ordinal - 1)::int AS place FROM ('
            . 'SELECT item.id, row_number() OVER (ORDER BY ' . $order . ') AS ordinal '
            . ItemListSql::FROM
            . $this->whereSql($where)
            . ') AS ranked WHERE ranked.id = ?::uuid';
        $rows = $this->statements->bound($sql, [...$params, ...$orderParams, strtolower($id)]);

        return $this->placeOf($rows[0]['place'] ?? null);
    }

    private function count(ItemPageQuery $query, string $scope): int
    {
        [$where, $params] = $this->where($query, $scope);
        $sql = 'SELECT count(*)::int AS total ' . ItemListSql::FROM . $this->whereSql($where);
        $rows = $params === [] ? $this->statements->rows($sql) : $this->statements->bound($sql, $params);

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slice(ItemPageQuery $query, int $start): array
    {
        [$where, $params] = $this->where($query, 'filtered');
        [$order, $orderParams] = $this->order($query);
        $sql = ItemListSql::SELECT . $this->whereSql($where) . ' ORDER BY ' . $order . $this->limit($query, $start);
        $bound = [...$params, ...$orderParams];
        if ($bound === []) {
            return $this->statements->rows($sql);
        }

        return $this->statements->bound($sql, $bound);
    }

    private function limit(ItemPageQuery $query, int $start): string
    {
        if ($query->window->mode === ItemPageWindow::ALL) {
            return '';
        }

        return ' LIMIT ' . $query->window->length . ' OFFSET ' . $start;
    }

    /**
     * @param list<string> $where
     */
    private function whereSql(array $where): string
    {
        if ($where === []) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function where(ItemPageQuery $query, string $scope): array
    {
        $parts = [];
        $params = [];
        if ($query->filter->vendorId !== '') {
            $parts[] = 'item.vendor_id = ?::uuid';
            $params[] = $query->filter->vendorId;
        }
        $stock = ItemStock::sql($query->filter->stock);
        if ($stock !== '') {
            $parts[] = $stock;
        }
        if ($scope === 'filtered') {
            [$extra, $more] = $this->filtered($query);
            $parts = [...$parts, ...$extra];
            $params = [...$params, ...$more];
        }

        return [$parts, $params];
    }

    /**
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function filtered(ItemPageQuery $query): array
    {
        $parts = [];
        $params = [];
        [$needle, $needles] = ItemSearch::clause($query->filter->query);
        if ($needle !== '') {
            $parts[] = $needle;
            $params = [...$params, ...$needles];
        }
        foreach ($query->filter->columns as $key => $value) {
            [$match, $bound] = $this->match($key, $value, $query->today);
            $parts[] = $match;
            $params = [...$params, ...$bound];
        }
        [$search, $bound] = $this->search($query);
        if ($search !== '') {
            $parts[] = $search;
            $params = [...$params, ...$bound];
        }

        return [$parts, $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function search(ItemPageQuery $query): array
    {
        if ($query->filter->search === '') {
            return ['', []];
        }
        $parts = [];
        $params = [];
        foreach ($query->filter->keys as $key) {
            if (!in_array($key, ItemPageQuery::SORTS, true)) {
                continue;
            }
            [$match, $bound] = $this->match($key, $query->filter->search, $query->today);
            $parts[] = $match;
            $params = [...$params, ...$bound];
        }
        if ($parts === []) {
            return ['', []];
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function match(string $key, string $needle, string $today): array
    {
        [$expr, $before] = ItemPageText::expression($key, $today);

        return ['strpos(lower(' . $expr . '), ?) > 0', [...$before, $needle]];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function order(ItemPageQuery $query): array
    {
        $way = $query->direction === 'desc' ? 'DESC' : 'ASC';
        if ($query->sort === 'days') {
            return [ItemPageText::daysOrder($way), [$query->today]];
        }
        if ($query->sort === 'status') {
            return [ItemPageText::statusOrder($way), []];
        }
        $pattern = self::ORDER[$query->sort] ?? '';
        if ($pattern === '') {
            throw new \InvalidArgumentException('That column cannot be sorted.');
        }

        return [sprintf($pattern, ...array_fill(0, substr_count($pattern, '%s'), $way)) . ', item.id ASC', []];
    }

    private function clamp(int $start, int $length, int $filtered): int
    {
        if ($filtered === 0 || $start < $filtered) {
            return $start;
        }
        if ($length < 1) {
            return 0;
        }

        return intdiv($filtered - 1, $length) * $length;
    }

    private function placeOf(mixed $place): int
    {
        if (is_int($place)) {
            return $place;
        }
        if (is_string($place) && preg_match('/^-?[0-9]+$/', $place) === 1) {
            return (int) $place;
        }

        return -1;
    }
}
