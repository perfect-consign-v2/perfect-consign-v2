<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * An item's count changes only through the database, with the reason, who and when.
 */
final class ItemCount
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * The store's switched-on reasons this person may choose for a lower count.
     *
     * @return list<array{id: string, code: string, label: string}>
     */
    public function reasons(CountWho $who): array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, COALESCE(code, \'\') AS code, label
             FROM quantity_reasons
             WHERE active
               AND CASE WHEN ? = \'vendor\' THEN vendor_may ELSE staff_may END
             ORDER BY position, lower(label)',
            [$who->kind]
        );
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'label' => RowValue::string($row['label'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * Sets the count. A lower count needs one of the reasons; a higher one needs none.
     */
    public function change(string $itemId, int $count, string $reasonId, CountWho $who): void
    {
        $this->statements->bound(
            'SELECT stall_change_item_quantity(?::uuid, ?, NULLIF(?, \'\')::uuid, ?, ?, ?::uuid)',
            [$itemId, $count, $reasonId, $who->kind, $who->name, $who->vendorId]
        );
    }

    /**
     * @return list<string>
     */
    public function history(string $itemId): array
    {
        return $this->said(
            'SELECT said FROM item_count_history WHERE item_id = ?::uuid ORDER BY changed_at DESC, id DESC LIMIT 50',
            [$itemId]
        );
    }

    /**
     * @return list<array{code: string, said: string}>
     */
    public function vendorHistory(string $vendorId): array
    {
        $rows = $this->statements->bound(
            'SELECT item.code, history.said
             FROM item_count_history AS history
             JOIN items AS item ON item.id = history.item_id
             WHERE history.vendor_id = ?::uuid
             ORDER BY history.changed_at DESC, history.id DESC
             LIMIT 20',
            [$vendorId]
        );
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = ['code' => RowValue::string($row['code'] ?? null), 'said' => RowValue::string($row['said'] ?? null)];
        }

        return $listed;
    }

    /**
     * @param list<mixed> $params
     * @return list<string>
     */
    private function said(string $sql, array $params): array
    {
        $listed = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $listed[] = RowValue::string($row['said'] ?? null);
        }

        return $listed;
    }
}
