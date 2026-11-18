<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class ItemQueries
{
    private const string LIST = ItemListSql::SELECT;

    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $query, string $stock = '', string $vendorId = ''): array
    {
        [$where, $params] = $this->filters($query, $stock, $vendorId);
        $sql = self::LIST . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY lower(item.code), item.code';
        if ($params === []) {
            return $this->statements->rows($sql);
        }

        return $this->statements->bound($sql, $params);
    }

    public function page(ItemPageQuery $query): ItemPage
    {
        return (new ItemPageRead($this->statements))->page($query);
    }

    public function place(string $query, string $stock, string $vendorId, string $today, string $id): int
    {
        return (new ItemPageRead($this->statements))->place(
            ItemPageQuery::scope($query, $stock, $vendorId, $today),
            $id,
        );
    }

    /**
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function filters(string $query, string $stock, string $vendorId): array
    {
        $where = [];
        $params = [];
        if ($vendorId !== '') {
            $where[] = 'item.vendor_id = ?::uuid';
            $params[] = $vendorId;
        }
        $stockClause = ItemStock::sql($stock);
        if ($stockClause !== '') {
            $where[] = $stockClause;
        }
        [$needle, $needles] = ItemSearch::clause(strtolower(trim($query)));
        if ($needle !== '') {
            $where[] = $needle;
            $params = array_merge($params, $needles);
        }

        return [$where, $params];
    }

    public function count(string $vendorId = ''): int
    {
        return $this->tally('', $vendorId);
    }

    public function available(string $vendorId = ''): int
    {
        return $this->tally(' WHERE quantity > 0', $vendorId);
    }

    public function unsold(string $vendorId = ''): int
    {
        return $this->tally(' WHERE quantity = 0', $vendorId);
    }

    public function sold(string $vendorId = ''): int
    {
        return $this->tally(
            ' WHERE quantity = 0 AND EXISTS (SELECT 1 FROM sale_lines AS sold WHERE sold.item_id = items.id LIMIT 1)',
            $vendorId
        );
    }

    public function unprinted(string $vendorId = ''): int
    {
        return $this->tally(' WHERE sticker_printed_at IS NULL', $vendorId);
    }

    private function tally(string $where, string $vendorId): int
    {
        if ($vendorId === '') {
            $rows = $this->statements->rows('SELECT count(*)::int AS total FROM items' . $where);
        } else {
            $rows = $this->statements->bound(
                'SELECT count(*)::int AS total FROM items' . ($where === '' ? ' WHERE ' : $where . ' AND ') . 'vendor_id = ?::uuid',
                [$vendorId]
            );
        }

        return RowValue::integer($rows[0]['total'] ?? 0);
    }
}
