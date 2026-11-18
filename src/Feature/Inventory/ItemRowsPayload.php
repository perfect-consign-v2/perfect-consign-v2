<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** DataTables row objects. Cell HTML comes from ItemCells. */
final class ItemRowsPayload
{
    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $columns
     * @return list<array<int|string, mixed>>
     */
    public function pack(array $items, array $columns, ItemCellContext $context, string $audience): array
    {
        $cells = new ItemCells();
        $packed = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }
            $row = [
                'DT_RowId' => 'item-' . $id,
                'DT_RowClass' => $this->rowClass($item, $audience),
            ];
            foreach ($cells->line($item, $columns, $context, $audience) as $index => $html) {
                $row[$index] = $html;
            }
            $packed[] = $row;
        }

        return $packed;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function rowClass(array $item, string $audience): string
    {
        if ($audience !== 'vendor') {
            return '';
        }
        $tier = $item['age_tier'] ?? null;
        if (!is_int($tier)) {
            return '';
        }

        return 'vendor-item-row--age-' . $tier;
    }
}
