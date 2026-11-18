<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** The items list select. Staff pages, vendor pages, and the full read share it. */
final class ItemListSql
{
    public const string FROM = 'FROM items AS item
                LEFT JOIN vendors AS vendor ON vendor.id = item.vendor_id
                CROSS JOIN mall
                LEFT JOIN brands AS brand ON brand.id = item.brand_id
                LEFT JOIN colors AS color ON color.id = item.color_id
                LEFT JOIN categories AS category ON category.id = item.category_id
                LEFT JOIN sizes AS size ON size.id = item.size_id
                LEFT JOIN conditions AS condition ON condition.id = item.condition_id
                LEFT JOIN departments AS department ON department.id = item.department_id';

    public const string SELECT = 'SELECT item.id, item.code, item.barcode, item.description, item.price_minor, item.quantity,
                       item.vendor_id, item.placement, item.commission_bps, item.intake_on, item.expires_on,
                       vendor.code AS vendor_code, vendor.name AS vendor_name,
                       COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps) AS take_bps,
                       brand.name AS brand_name,
                       color.name AS color_name, category.name AS category_name,
                       size.name AS size_name, condition.name AS condition_name,
                       department.name AS department_name,
                       EXISTS (
                           SELECT 1 FROM sale_lines AS sold WHERE sold.item_id = item.id LIMIT 1
                       ) AS has_sale
                ' . self::FROM;
}
