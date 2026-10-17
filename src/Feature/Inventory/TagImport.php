<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;

final class TagImport
{
    private const int LIMIT = 500;

    public function __construct(
        private readonly Statements $statements,
        private readonly ItemWriter $writer,
        private readonly InventoryStore $items,
        private readonly IntakeCatalog $catalog,
        private readonly MallSettings $mall,
    ) {
    }

    public function load(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('That file could not be read.');
        }
        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) {
                throw new \InvalidArgumentException('That file has no tags.');
            }
            $this->requireHeader($header);
            $rows = $this->rows($handle);
        } finally {
            fclose($handle);
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('That file has no tags.');
        }
        $this->statements->transaction(function () use ($rows): void {
            $today = $this->mall->today();
            foreach ($rows as $row) {
                $this->items->add($this->writer->entry($row), $today);
            }
        });

        return count($rows);
    }

    /**
     * @param array<int, string|null> $header
     */
    private function requireHeader(array $header): void
    {
        $names = [];
        foreach ($header as $name) {
            $names[] = strtolower(trim((string) $name));
        }
        $names[0] = preg_replace('/^\xEF\xBB\xBF/', '', $names[0] ?? '') ?? '';
        if ($names !== ['code', 'vendor', 'description', 'price', 'barcode', 'quantity']) {
            throw new \InvalidArgumentException('The first row must be code, vendor, description, price, barcode, quantity.');
        }
    }

    /**
     * @param resource $handle
     * @return list<array{
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
     * }>
     */
    private function rows($handle): array
    {
        $rows = [];
        $number = 1;
        while (($line = fgetcsv($handle)) !== false) {
            $number++;
            if ($this->blank($line)) {
                continue;
            }
            if (count($rows) >= self::LIMIT) {
                throw new \InvalidArgumentException('Bring in 500 tags at a time.');
            }
            $rows[] = $this->draft($line, $number);
        }

        return $rows;
    }

    private function requireCells(int $number, string $code, string $vendorCode, string $description, string $price): void
    {
        if ($code === '' || $vendorCode === '' || $description === '' || $price === '') {
            throw new \InvalidArgumentException('Row ' . $number . ' needs a code, a vendor, a description, and a price.');
        }
    }

    /**
     * @param array<int, string|null> $line
     */
    private function blank(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string|null> $line
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
    private function draft(array $line, int $number): array
    {
        $code = trim((string) ($line[0] ?? ''));
        $vendorCode = trim((string) ($line[1] ?? ''));
        $description = trim((string) ($line[2] ?? ''));
        $price = trim((string) ($line[3] ?? ''));
        $barcode = trim((string) ($line[4] ?? ''));
        $quantity = trim((string) ($line[5] ?? ''));
        $this->requireCells($number, $code, $vendorCode, $description, $price);
        $vendor = $this->catalog->vendorByCode($vendorCode);
        $vendorId = is_array($vendor) && is_string($vendor['id'] ?? null) ? $vendor['id'] : '';
        if ($vendorId === '') {
            throw new \InvalidArgumentException('Row ' . $number . ' uses a vendor code that is not in this mall.');
        }
        $attributes = [];
        foreach (NameList::all() as $list) {
            $attributes[$list->key] = ['id' => '', 'label' => ''];
        }

        return [
            'id' => '',
            'code' => $code,
            'barcode' => $barcode,
            'description' => $description,
            'price' => $price,
            'quantity' => $quantity === '' ? '1' : $quantity,
            'vendor_id' => $vendorId,
            'vendor_label' => '',
            'brand_id' => '',
            'brand_label' => '',
            'attributes' => $attributes,
            'store_take' => '',
            'intake_on' => $this->mall->today(),
            'expires_on' => '',
            'fill_expire' => '',
        ];
    }
}
