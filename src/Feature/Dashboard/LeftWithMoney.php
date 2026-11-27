<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dashboard;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Vendors who have left the store with money still open, in the database's own
 * words, until each balance is zero.
 */
final class LeftWithMoney
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{vendor_id: string, name: string, said: string}>
     */
    public function vendors(): array
    {
        $listed = [];
        foreach (
            $this->statements->rows(
                "SELECT vendor.id::text AS vendor_id, vendor.name,
                        string_agg(open.sentence, ' ' ORDER BY open.ordinality) AS said
                 FROM vendors AS vendor
                 CROSS JOIN LATERAL stall_vendor_open_reasons(vendor.id) WITH ORDINALITY AS open(reason, sentence, ordinality)
                 WHERE NOT vendor.active
                   AND open.reason <> 'booth'
                 GROUP BY vendor.id, vendor.name
                 ORDER BY vendor.name"
            ) as $row
        ) {
            $listed[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'said' => RowValue::string($row['said'] ?? null),
            ];
        }

        return $listed;
    }
}
