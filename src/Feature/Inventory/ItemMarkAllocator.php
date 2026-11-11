<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;

/** Allocates sequential TG/BC marks for blank item tags and barcodes. */
final class ItemMarkAllocator
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function tag(string $today): string
    {
        return $this->allocate('TG', 'code', 'A tag could not be generated.', $today);
    }

    public function barcode(string $today): string
    {
        return $this->allocate('BC', 'barcode', 'A barcode could not be generated.', $today);
    }

    private function allocate(string $mark, string $column, string $failure, string $today): string
    {
        if ($column !== 'code' && $column !== 'barcode') {
            throw new \InvalidArgumentException($failure);
        }
        $stamp = str_replace('-', '', $today);
        if (preg_match('/^\d{8}$/', $stamp) !== 1) {
            throw new \InvalidArgumentException($failure);
        }
        $prefix = $mark . $stamp;
        $rows = $this->statements->bound(
            'SELECT ' . $column . ' AS mark FROM items WHERE ' . $column . ' ~ ? ORDER BY ' . $column . ' DESC LIMIT 1',
            ['^' . $prefix . '[0-9]{4}$']
        );
        $last = $rows[0]['mark'] ?? null;
        $sequence = 1;
        if (is_string($last) && preg_match('/(\d{4})$/', $last, $parts) === 1) {
            $sequence = ((int) $parts[1]) + 1;
        }
        if ($sequence > 9999) {
            throw new \InvalidArgumentException($failure);
        }

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
