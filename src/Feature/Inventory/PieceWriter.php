<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Scalar\RowValue;

final class PieceWriter
{
    public function __construct(
        private readonly IntakeCatalog $catalog,
        private readonly NameListStore $lists,
        private readonly MallSettings $mall,
        private readonly StoreTake $take,
    ) {
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
     */
    public function entry(array $draft): PieceEntry
    {
        $intake = $this->date($draft['intake_on']);
        if ($intake === null) {
            throw new \InvalidArgumentException('Enter an intake date.');
        }

        return new PieceEntry(
            $draft['code'],
            $draft['barcode'],
            $draft['vendor_id'],
            $this->brandOf($draft['brand_id'], $draft['brand_label']),
            $this->attributesOf($draft['attributes']),
            $draft['description'],
            Money::parseMinor($draft['price']),
            $this->quantity($draft['quantity'], $draft['id'] === ''),
            $this->takeOf($draft['vendor_id'], $draft['store_take']),
            new PieceDates($intake, $this->expires($draft['expires_on'], $intake)),
        );
    }

    private function quantity(string $value, bool $creating): int
    {
        $text = $value === '' ? '1' : $value;
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})$/', $text) !== 1) {
            throw new \InvalidArgumentException('Enter a whole quantity.');
        }
        $count = (int) $text;
        if ($creating && $count < 1) {
            throw new \InvalidArgumentException('Enter a quantity of at least 1.');
        }

        return $count;
    }

    private function brandOf(string $brandId, string $label): ?string
    {
        $id = $this->uuid($brandId);
        if ($id !== '') {
            return $id;
        }
        if ($label === '') {
            return null;
        }
        $found = $this->catalog->brandId($label);
        if ($found === null) {
            throw new \InvalidArgumentException('Add that brand before saving the piece.');
        }

        return $found;
    }

    /**
     * @param array<string, array{id: string, label: string}> $posted
     * @return array<string, string|null>
     */
    private function attributesOf(array $posted): array
    {
        $chosen = [];
        foreach (NameList::all() as $list) {
            $field = $posted[$list->key] ?? ['id' => '', 'label' => ''];
            $id = $this->attributeOf($list, $field['id'], $field['label']);
            if (!$this->mall->enabled($list->flag)) {
                if ($id !== null) {
                    throw new \InvalidArgumentException(ucfirst($list->singular) . ' is turned off.');
                }
                $chosen[$list->key] = null;
                continue;
            }
            $chosen[$list->key] = $id;
        }

        return $chosen;
    }

    private function attributeOf(NameList $list, string $postedId, string $label): ?string
    {
        $id = $this->uuid($postedId);
        if ($id !== '') {
            return $id;
        }
        if ($label === '') {
            return null;
        }
        $found = $this->lists->idForName($list, $label);
        if ($found === null) {
            throw new \InvalidArgumentException('Add that ' . $list->singular . ' before saving the piece.');
        }

        return $found;
    }

    private function takeOf(string $vendorId, string $posted): ?int
    {
        if ($posted === '') {
            return null;
        }
        $basis = $this->take->fromPercent($posted);
        if ($basis === null) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
        $vendor = $this->catalog->vendor($vendorId);
        $effective = $vendor === null ? $this->mall->storeTake() : RowValue::integer($vendor['take_bps'] ?? null);
        if ($basis === $effective) {
            return null;
        }

        return $basis;
    }

    private function expires(string $value, string $intake): ?string
    {
        if ($value === '') {
            return null;
        }
        $day = $this->date($value);
        if ($day === null) {
            throw new \InvalidArgumentException('Enter an expire date.');
        }
        if ($day < $intake) {
            throw new \InvalidArgumentException('The expire date is before the intake date.');
        }

        return $day;
    }

    private function date(string $value): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    private function uuid(string $value): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }
}
