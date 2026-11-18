<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Mall\MallSettings;

/** Dates, sticker stock, and whether a vendor may edit items. */
final class VendorItemCatalog
{
    public function __construct(
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
}
