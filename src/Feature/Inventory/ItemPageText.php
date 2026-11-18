<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** The cell text a column filter and a sort compare. The SQL is fixed here, never from the request. */
final class ItemPageText
{
    private const string STATUS = "CASE WHEN item.quantity > 0 THEN 'On floor'"
        . " WHEN EXISTS (SELECT 1 FROM sale_lines AS sold WHERE sold.item_id = item.id) THEN 'Sold'"
        . " ELSE 'Out' END";

    private const string PRICE = "(CASE WHEN item.price_minor < 0 THEN '-' ELSE '' END)"
        . " || '$' || (abs(item.price_minor) / 100)::text || '.'"
        . " || lpad((abs(item.price_minor) % 100)::text, 2, '0')";

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function expression(string $key, string $today): array
    {
        $plain = self::plain($key);
        if ($plain !== '') {
            return [$plain, []];
        }
        if ($key === 'days') {
            return [self::daysText(), [$today]];
        }
        $made = self::made($key);
        if ($made === '') {
            throw new \InvalidArgumentException('That column cannot be filtered.');
        }

        return [$made, []];
    }

    public static function daysOrder(string $way): string
    {
        return 'CASE WHEN item.quantity < 1 OR item.intake_on IS NULL THEN NULL'
            . ' ELSE GREATEST((?::date - item.intake_on), 0) END ' . $way
            . ' NULLS LAST, item.id ASC';
    }

    public static function statusOrder(string $way): string
    {
        return self::STATUS . ' ' . $way . ', item.id ASC';
    }

    private static function plain(string $key): string
    {
        $columns = [
            'code' => 'item.code',
            'barcode' => "coalesce(item.barcode, '')",
            'description' => 'item.description',
            'brand' => "coalesce(brand.name, '')",
            'color' => "coalesce(color.name, '')",
            'category' => "coalesce(category.name, '')",
            'size' => "coalesce(size.name, '')",
            'condition' => "coalesce(condition.name, '')",
            'department' => "coalesce(department.name, '')",
            'quantity' => 'item.quantity::text',
        ];

        return $columns[$key] ?? '';
    }

    private static function made(string $key): string
    {
        return match ($key) {
            'vendor' => self::vendorLabel(),
            'intake_on' => "coalesce(to_char(item.intake_on, 'MM-DD-YYYY'), '')",
            'expires_on' => self::expiresText(),
            'status' => self::STATUS,
            'take' => self::takeText(),
            'price' => self::PRICE,
            default => '',
        };
    }

    private static function daysText(): string
    {
        return "CASE WHEN item.quantity < 1 OR item.intake_on IS NULL THEN '" . self::dash()
            . "' ELSE GREATEST((?::date - item.intake_on), 0)::text END";
    }

    private static function expiresText(): string
    {
        return "CASE WHEN item.expires_on IS NULL THEN '" . self::dash()
            . "' ELSE to_char(item.expires_on, 'MM-DD-YYYY') END";
    }

    private static function vendorLabel(): string
    {
        $dash = self::dash();

        return "CASE WHEN vendor.id IS NULL AND item.placement = 'store' THEN 'Store stock'"
            . " WHEN vendor.id IS NULL THEN ''"
            . " ELSE '#' || vendor.code || ' " . $dash . " ' || vendor.name END";
    }

    private static function takeText(): string
    {
        $bps = 'COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps)';

        return 'CASE WHEN ' . $bps . ' % 100 = 0 THEN (' . $bps . ' / 100)::text'
            . ' WHEN ' . $bps . ' % 10 = 0 THEN (' . $bps . ' / 100)::text || \'.\' || ((' . $bps . ' % 100) / 10)::text'
            . ' ELSE (' . $bps . ' / 100)::text || \'.\' || lpad((' . $bps . ' % 100)::text, 2, \'0\')'
            . " END || '%' || CASE WHEN item.commission_bps IS NOT NULL THEN ' Overridden' ELSE '' END";
    }

    private static function dash(): string
    {
        return "\u{2014}";
    }
}
