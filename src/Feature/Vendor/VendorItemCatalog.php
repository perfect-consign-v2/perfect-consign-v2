<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Brand\BrandStore;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Mall\MallSettings;

/** Enabled brand and attribute choices for the vendor item form. */
final class VendorItemCatalog
{
    public function __construct(
        private readonly BrandStore $brands,
        private readonly NameListStore $lists,
        private readonly MallSettings $mall,
        private readonly LabelCatalog $labels,
    ) {
    }

    public function today(): string
    {
        return $this->mall->today();
    }

    public function inventoryOpen(): bool
    {
        return (new VendorPortalAccess($this->mall))->inventoryEnabled();
    }

    public function floorDayCount(): ?int
    {
        return $this->mall->floorDays();
    }

    public function labelStock(): string
    {
        return $this->mall->getLabelStock();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function stocks(): array
    {
        return $this->labels->choices();
    }

    public function brandOn(): bool
    {
        return $this->mall->enabled('use_brand');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function brands(): array
    {
        if (!$this->brandOn()) {
            return [];
        }

        return $this->named($this->brands->brands(''));
    }

    /**
     * @return list<array{key: string, singular: string, rows: list<array{id: string, name: string}>}>
     */
    public function attributes(): array
    {
        $groups = [];
        foreach ($this->mall->getEnabledLists() as $list) {
            $groups[] = [
                'key' => $list->key,
                'singular' => $list->singular,
                'rows' => $this->named($this->lists->rows($list, '')),
            ];
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: string, name: string}>
     */
    private function named(array $rows): array
    {
        $named = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($id) || !is_string($name) || $id === '' || $name === '') {
                continue;
            }
            $named[] = ['id' => $id, 'name' => $name];
        }

        return $named;
    }
}
