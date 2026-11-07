<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class InstantAdd
{
    public function __construct(
        public readonly string $vendorId,
        public readonly string $description,
        public readonly string $price,
        public readonly string $barcode,
        public readonly string $quantity,
        public readonly InstantBrand $brand,
        /** @var array<string, array{id: string, label: string}> */
        public readonly array $attributes,
        public readonly InstantDiscount $discount,
        public readonly InstantOverride $override = new InstantOverride(),
    ) {
    }

    /**
     * @param array{
     *     vendor_id: string,
     *     description: string,
     *     price: string,
     *     brand_id: string,
     *     brand_label: string,
     *     discount_kind: string,
     *     discount_value: string,
     *     override_user: string,
     *     override_password: string
     * } $fields
     * @param array<string, array{id: string, label: string}> $attributes
     */
    public static function posted(array $fields, array $attributes): self
    {
        return new self(
            $fields['vendor_id'],
            $fields['description'],
            $fields['price'],
            '',
            '1',
            new InstantBrand($fields['brand_id'], $fields['brand_label']),
            $attributes,
            new InstantDiscount($fields['discount_kind'], $fields['discount_value']),
            new InstantOverride($fields['override_user'], $fields['override_password']),
        );
    }
}
