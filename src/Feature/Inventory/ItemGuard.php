<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Shared\Database\Statements;

final class ItemGuard
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function accept(ItemEntry $item): void
    {
        $this->requireVendor($item->vendorId);
        $this->requireBrand($item->brandId);
        $this->requireAttributes($item->attributes);
    }

    private function requireVendor(string $vendorId): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
        $rows = $this->statements->bound(
            'SELECT id FROM vendors WHERE id = ?::uuid',
            [$vendorId]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
    }

    private function requireBrand(?string $brandId): void
    {
        if ($brandId === null || $brandId === '') {
            return;
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $brandId) !== 1) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
        $rows = $this->statements->bound(
            'SELECT id FROM brands WHERE id = ?::uuid',
            [$brandId]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }

    /**
     * @param array<string, string|null> $attributes
     */
    private function requireAttributes(array $attributes): void
    {
        foreach (NameList::all() as $list) {
            $id = $attributes[$list->key] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
                throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
            }
            $rows = $this->statements->bound(
                'SELECT id FROM ' . $list->table . ' WHERE id = ?::uuid',
                [$id]
            );
            if ($rows === []) {
                throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
            }
        }
    }
}
