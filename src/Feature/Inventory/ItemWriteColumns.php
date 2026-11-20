<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\PgBool;

final class ItemWriteColumns
{
    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<mixed>}
     */
    public static function fields(MallSettings $mall, ItemEntry $item, string $code, string $barcode): array
    {
        $columns = ['code', 'barcode', 'vendor_id', 'placement'];
        $casts = ['?', '?', '?::uuid', '?'];
        $params = [
            $code,
            $barcode,
            $item->vendorId === '' ? null : $item->vendorId,
            $item->placement,
        ];
        if ($mall->enabled('use_brand')) {
            $columns[] = 'brand_id';
            $casts[] = '?::uuid';
            $params[] = $item->brandId;
        }
        $columns = array_merge($columns, ['description', 'price_minor', 'quantity', 'commission_bps', 'tax_exempt', 'intake_on', 'expires_on']);
        $casts = array_merge($casts, ['?', '?', '?', '?', '?::boolean', '?::date', '?::date']);
        $params = array_merge($params, [
            $item->description,
            $item->sale->priceMinor,
            $item->sale->quantity,
            $item->sale->commissionBps,
            PgBool::literal($item->sale->taxExempt),
            $item->dates->intakeOn,
            $item->dates->expiresOn,
        ]);
        foreach (NameList::all() as $list) {
            if (!$mall->enabled($list->flag)) {
                continue;
            }
            $columns[] = $list->itemColumn;
            $casts[] = '?::uuid';
            $params[] = $item->attributes[$list->key] ?? null;
        }

        return [$columns, $casts, $params];
    }
}
