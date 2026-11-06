<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Feature\Inventory\ItemDates;
use PerfectConsign\Feature\Inventory\ItemEntry;
use PerfectConsign\Feature\Inventory\ItemSale;
use PerfectConsign\Feature\Mall\MallDay;

final class ItemMasterLoader
{
    /** @var array<string, true> */
    private array $usedCodes = [];

    /** @var array<string, true> */
    private array $usedBarcodes = [];

    public function __construct(private readonly MirrorInventoryFactory $inventory = new MirrorInventoryFactory())
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $vendors
     */
    public function load(MirrorMall $mall, array $rows, array $vendors): int
    {
        $inventory = $this->inventory->store($mall);
        $today = (new MallDay($mall->statements))->today();
        $count = 0;
        foreach ($rows as $row) {
            $vendorId = $vendors[MirrorFields::text($row['pc1_consignor_id'] ?? null)] ?? null;
            if ($vendorId === null) {
                continue;
            }
            $price = MirrorFields::cents($row['price_minor'] ?? 0);
            if ($price < 1) {
                continue;
            }
            $intake = MirrorFields::text($row['intake_on'] ?? null, $today);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $intake) !== 1) {
                $intake = $today;
            }
            $code = $this->unique(MirrorFields::text($row['code'] ?? null, 'ITEM'), $this->usedCodes, 40);
            $barcode = MirrorFields::text($row['barcode'] ?? null);
            if ($barcode !== '') {
                $barcode = $this->unique($barcode, $this->usedBarcodes, 100);
            }
            $inventory->add(new ItemEntry(
                $code,
                $barcode,
                $vendorId,
                null,
                [],
                MirrorFields::text($row['description'] ?? null, 'Item'),
                new ItemSale($price, max(1, MirrorFields::cents($row['quantity'] ?? 1)), null),
                new ItemDates($intake, null),
                'consigned',
            ), $today);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, true> $used
     */
    private function unique(string $value, array &$used, int $maxLen): string
    {
        $base = substr($value, 0, $maxLen);
        $candidate = $base;
        $n = 2;
        while (isset($used[$candidate])) {
            $suffix = '-' . $n;
            $candidate = substr($base, 0, max(1, $maxLen - strlen($suffix))) . $suffix;
            $n++;
            if ($n > 9999) {
                throw new MirrorException('could not uniquify item code ' . $value);
            }
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
