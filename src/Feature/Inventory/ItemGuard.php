<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\CatalogFlag;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\Statements;

final class ItemGuard
{
    public function __construct(
        private readonly Statements $statements,
        private readonly ?MallSettings $mall = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $current
     */
    public function accept(ItemEntry $item, ?array $current = null): void
    {
        $this->requireVendor($item->vendorId);
        $this->live('brands', 'brand', $item->brandId, $this->kept($current, 'brand_id'), 'use_brand');
        $this->requireAttributes($item->attributes, $current);
    }

    private function requireVendor(string $vendorId): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
        $rows = $this->statements->bound('SELECT id FROM vendors WHERE id = ?::uuid', [$vendorId]);
        if ($rows === []) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
    }

    /**
     * @param array<string, string|null> $attributes
     * @param array<string, mixed>|null $current
     */
    private function requireAttributes(array $attributes, ?array $current): void
    {
        foreach (NameList::all() as $list) {
            $next = $attributes[$list->key] ?? null;
            $this->live(
                $list->table,
                $list->singular,
                is_string($next) ? $next : null,
                $this->kept($current, $list->itemColumn),
                $list->flag,
            );
        }
    }

    private function live(string $table, string $noun, ?string $next, ?string $kept, string $flag): void
    {
        if ($next === null || $next === '' || $this->same($next, $kept)) {
            return;
        }
        $this->present($table, $noun, $next, $flag);
    }

    private function present(string $table, string $noun, string $id, string $flag): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('That ' . $noun . ' is not in this mall.');
        }
        if (!$this->table($table)) {
            throw new \InvalidArgumentException('that catalog table is not a name');
        }
        $rows = $this->statements->bound('SELECT active FROM ' . $table . ' WHERE id = ?::uuid', [$id]);
        if ($rows === []) {
            throw new \InvalidArgumentException('That ' . $noun . ' is not in this mall.');
        }
        if ($this->enabled($flag) && !CatalogFlag::on($rows[0]['active'] ?? null)) {
            throw new \InvalidArgumentException('That ' . $noun . ' is inactive.');
        }
    }

    private function enabled(string $flag): bool
    {
        return $this->mall === null || $this->mall->enabled($flag);
    }

    private function same(string $next, ?string $kept): bool
    {
        return $kept !== null && strcasecmp($next, $kept) === 0;
    }

    private function table(string $table): bool
    {
        return in_array($table, ['brands', 'colors', 'categories', 'sizes', 'conditions', 'departments'], true);
    }

    /**
     * @param array<string, mixed>|null $current
     */
    private function kept(?array $current, string $column): ?string
    {
        if ($current === null) {
            return null;
        }
        $value = $current[$column] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
