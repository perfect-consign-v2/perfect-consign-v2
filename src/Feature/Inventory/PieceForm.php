<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

final class PieceForm
{
    public function __construct(
        private readonly IntakeCatalog $catalog,
        private readonly NameListStore $lists,
        private readonly MallSettings $mall,
        private readonly StoreTake $take,
    ) {
    }

    /**
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
     *     fill_expire: string
     * }
     */
    public function blank(string $vendorId, string $intake): array
    {
        return $this->posted([
            'id' => '',
            'code' => '',
            'barcode' => '',
            'description' => '',
            'price' => '',
            'quantity' => '',
            'vendor_id' => $vendorId,
            'vendor_label' => '',
            'brand_id' => '',
            'brand_label' => '',
            'attributes' => $this->emptyAttributes(),
            'store_take' => '',
            'intake_on' => $this->date($intake) ?? $this->mall->today(),
            'expires_on' => '',
            'fill_expire' => '1',
        ]);
    }

    /**
     * @param array<string, mixed> $row
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
     *     fill_expire: string
     * }
     */
    public function stored(array $row): array
    {
        $saved = $row['commission_bps'] ?? null;
        $brandId = $row['brand_id'] ?? null;
        $brandName = $row['brand_name'] ?? null;
        $expires = $row['expires_on'] ?? null;

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'code' => RowValue::string($row['code'] ?? null),
            'barcode' => RowValue::string($row['barcode'] ?? null),
            'description' => RowValue::string($row['description'] ?? null),
            'price' => View::money(View::minor($row['price_minor'] ?? null)),
            'quantity' => (string) RowValue::integer($row['quantity'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'vendor_label' => $this->catalog->label(
                RowValue::string($row['vendor_code'] ?? null),
                RowValue::string($row['vendor_name'] ?? null),
            ),
            'brand_id' => is_string($brandId) ? $brandId : '',
            'brand_label' => is_string($brandName) ? $brandName : '',
            'attributes' => $this->storedAttributes($row),
            'store_take' => $this->take->format($saved === null
                ? RowValue::integer($row['vendor_take_bps'] ?? null)
                : RowValue::integer($saved)),
            'intake_on' => RowValue::string($row['intake_on'] ?? null),
            'expires_on' => is_string($expires) ? $expires : '',
            'fill_expire' => '',
        ];
    }

    /**
     * @param array{
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
     *     fill_expire: string
     * } $draft
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
     *     fill_expire: string
     * }
     */
    public function posted(array $draft): array
    {
        $vendor = $this->chosenVendor($draft['vendor_id']);
        $draft['vendor_id'] = $vendor['id'];
        if ($vendor['label'] !== '') {
            $draft['vendor_label'] = $vendor['label'];
        }
        if ($draft['quantity'] === '') {
            $draft['quantity'] = '1';
        }
        if ($draft['store_take'] === '') {
            $draft['store_take'] = $vendor['take'];
        }
        $brand = $this->chosenBrand($draft['brand_id'], $draft['brand_label']);
        $draft['brand_id'] = $brand['id'];
        $draft['brand_label'] = $brand['label'];
        $draft['attributes'] = $this->chosenAttributes($draft['attributes']);
        if ($draft['fill_expire'] === '1' && $draft['expires_on'] === '') {
            $draft['expires_on'] = $this->expectedExpire($draft['intake_on']);
        }

        return $draft;
    }

    /**
     * @param array{
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
     *     fill_expire: string
     * } $piece
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
     *     default_take: string,
     *     overridden: bool,
     *     intake_on: string,
     *     expires_on: string,
     *     expire_dirty: bool
     * }
     */
    public function view(array $piece): array
    {
        $vendor = $this->chosenVendor($piece['vendor_id']);
        $basis = $piece['store_take'] === '' ? null : $this->take->fromPercent($piece['store_take']);
        $defaultBasis = $this->take->fromPercent($vendor['take']);

        return [
            'id' => $piece['id'],
            'code' => $piece['code'],
            'barcode' => $piece['barcode'],
            'description' => $piece['description'],
            'price' => $piece['price'],
            'quantity' => $piece['quantity'],
            'vendor_id' => $piece['vendor_id'],
            'vendor_label' => $piece['vendor_label'],
            'brand_id' => $piece['brand_id'],
            'brand_label' => $piece['brand_label'],
            'attributes' => $piece['attributes'],
            'store_take' => $piece['store_take'],
            'default_take' => $vendor['take'],
            'overridden' => $basis !== null && $basis !== $defaultBasis,
            'intake_on' => $piece['intake_on'],
            'expires_on' => $piece['expires_on'],
            'expire_dirty' => $piece['expires_on'] !== $this->expectedExpire($piece['intake_on']),
        ];
    }

    public function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{id: string, label: string, take: string}
     */
    private function chosenVendor(string $vendorId): array
    {
        $id = $this->uuid($vendorId);
        $vendor = $id === '' ? null : $this->catalog->vendor($id);
        if ($vendor === null) {
            return ['id' => '', 'label' => '', 'take' => $this->take->format($this->mall->storeTake())];
        }

        return [
            'id' => $id,
            'label' => $this->catalog->label(
                RowValue::string($vendor['code'] ?? null),
                RowValue::string($vendor['name'] ?? null),
            ),
            'take' => $this->take->format(RowValue::integer($vendor['take_bps'] ?? null)),
        ];
    }

    /**
     * @return array{id: string, label: string}
     */
    private function chosenBrand(string $brandId, string $label): array
    {
        $id = $this->uuid($brandId);
        if ($id === '') {
            return ['id' => '', 'label' => $label];
        }
        $brand = $this->catalog->brand($id);
        if ($brand === null) {
            return ['id' => '', 'label' => $label];
        }

        return ['id' => $id, 'label' => RowValue::string($brand['name'] ?? null)];
    }

    /**
     * @return array<string, array{id: string, label: string}>
     */
    private function emptyAttributes(): array
    {
        $fields = [];
        foreach (NameList::all() as $list) {
            $fields[$list->key] = ['id' => '', 'label' => ''];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array{id: string, label: string}>
     */
    private function storedAttributes(array $row): array
    {
        $fields = [];
        foreach (NameList::all() as $list) {
            $id = $row[$list->itemColumn] ?? null;
            $name = $row[$list->key . '_name'] ?? null;
            $fields[$list->key] = [
                'id' => is_string($id) ? $id : '',
                'label' => is_string($name) ? $name : '',
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, array{id: string, label: string}> $posted
     * @return array<string, array{id: string, label: string}>
     */
    private function chosenAttributes(array $posted): array
    {
        $fields = [];
        foreach (NameList::all() as $list) {
            $field = $posted[$list->key] ?? ['id' => '', 'label' => ''];
            $id = $this->uuid($field['id']);
            if ($id === '') {
                $fields[$list->key] = ['id' => '', 'label' => $field['label']];
                continue;
            }
            $row = $this->lists->named($list, $id);
            $fields[$list->key] = [
                'id' => $row === null ? '' : $id,
                'label' => $row === null ? $field['label'] : RowValue::string($row['name'] ?? null),
            ];
        }

        return $fields;
    }

    private function expectedExpire(string $intake): string
    {
        $days = $this->mall->floorDays();
        if ($days === null || $this->date($intake) === null) {
            return '';
        }

        return $this->shift($intake, $days);
    }

    private function shift(string $day, int $days): string
    {
        $date = new \DateTimeImmutable($day);

        return $date->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function uuid(string $value): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }
}
