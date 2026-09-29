<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class ScanSearch
{
    public function __construct(
        private readonly Statements $statements,
    ) {
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function matches(string $query): array
    {
        $needle = strtolower(trim($query));
        if ($needle === '' || strlen($needle) > 80) {
            return [];
        }
        $rows = $this->statements->bound(
            'SELECT item.code, item.description, vendor.name AS vendor_name
             FROM items AS item
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             WHERE item.quantity > 0
               AND (
                   strpos(lower(item.code), ?) > 0
                   OR strpos(lower(item.barcode), ?) > 0
                   OR strpos(lower(item.description), ?) > 0
                   OR strpos(lower(vendor.name), ?) > 0
               )
             ORDER BY lower(item.code), item.code
             LIMIT 8',
            [$needle, $needle, $needle, $needle]
        );
        $matches = [];
        foreach ($rows as $row) {
            $code = RowValue::string($row['code'] ?? null);
            $matches[] = [
                'code' => $code,
                'label' => $code . ' — ' . RowValue::string($row['description'] ?? null) . ' — ' . RowValue::string($row['vendor_name'] ?? null),
            ];
        }

        return $matches;
    }
}
