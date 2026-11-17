<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemEntry;
use PerfectConsign\Feature\Inventory\ItemWriter;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Builds the staff item draft a vendor is allowed to save.
 *
 * Tag, barcode, vendor, store take, tax, cost, and store placement stay off the form.
 */
final class VendorItemDraft
{
    public function __construct(
        private readonly ItemWriter $writer,
        private readonly StoreTake $take,
        private readonly VendorPlacement $placement,
        private readonly InventoryStore $items,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isSoldOut(array $row): bool
    {
        $id = RowValue::string($row['id'] ?? null);
        if ($id === '' || RowValue::integer($row['quantity'] ?? null) > 0) {
            return false;
        }

        return $this->items->sold($id);
    }

    /**
     * @param array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * } $posted
     */
    public function create(array $posted, string $vendorId, string $today): ItemEntry
    {
        $placement = $this->placement->forCreate($posted['placement'], $vendorId, $today);

        return $this->writer->entry($this->fields(
            '',
            '',
            '',
            $posted,
            $vendorId,
            '',
            '',
            $placement,
            $posted['price'],
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @param array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * } $posted
     */
    public function update(array $row, array $posted, string $vendorId): ItemEntry
    {
        if ($this->isSoldOut($row)) {
            throw new \InvalidArgumentException('That item is sold.');
        }
        $placement = $this->placement->forEdit(RowValue::string($row['placement'] ?? null));

        return $this->writer->entry($this->fields(
            RowValue::string($row['id'] ?? null),
            RowValue::string($row['code'] ?? null),
            RowValue::string($row['barcode'] ?? null),
            $posted,
            $vendorId,
            $this->storedTake($row),
            MallGuard::flag($row['tax_exempt'] ?? null) ? '1' : '',
            $placement,
            $this->price($row, $posted['price']),
        ));
    }

    /**
     * @param array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * } $posted
     * @return array{
     *     id: string,
     *     code: string,
     *     barcode: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     vendor_id: string,
     *     vendor_label: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     store_take: string,
     *     intake_on: string,
     *     expires_on: string,
     *     fill_expire: string,
     *     placement: string,
     *     tax_exempt: string
     * }
     */
    private function fields(
        string $id,
        string $code,
        string $barcode,
        array $posted,
        string $vendorId,
        string $storeTake,
        string $taxExempt,
        string $placement,
        string $price,
    ): array {
        $description = trim($posted['description']);
        if ($description === '') {
            throw new \InvalidArgumentException('Enter a description.');
        }

        return [
            'id' => $id,
            'code' => $code,
            'barcode' => $barcode,
            'description' => $description,
            'price' => $price,
            'quantity' => $posted['quantity'],
            'vendor_id' => $vendorId,
            'vendor_label' => '',
            'brand_id' => $posted['brand_id'],
            'brand_label' => $posted['brand_label'],
            'attributes' => $posted['attributes'],
            'store_take' => $storeTake,
            'intake_on' => $posted['intake_on'],
            'expires_on' => $placement === 'consigned' ? $posted['expires_on'] : '',
            'fill_expire' => '',
            'placement' => $placement,
            'tax_exempt' => $taxExempt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storedTake(array $row): string
    {
        if (($row['commission_bps'] ?? null) === null) {
            return '';
        }

        return $this->take->format(RowValue::integer($row['commission_bps']));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function price(array $row, string $posted): string
    {
        $id = RowValue::string($row['id'] ?? null);
        if ($id === '' || !$this->items->sold($id)) {
            return $posted;
        }
        if (Money::parseMinor($posted) !== RowValue::integer($row['price_minor'] ?? null)) {
            throw new \InvalidArgumentException('That price is already on a sale.');
        }

        return View::money(View::minor($row['price_minor'] ?? null));
    }
}
