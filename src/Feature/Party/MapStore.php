<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MapStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}>
     */
    public function stays(): array
    {
        $rows = $this->statements->rows(
            'SELECT assignment.booth_id, vendor.name AS vendor_name, assignment.starts_on, assignment.ends_on
             FROM booth_assignments AS assignment
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             ORDER BY assignment.starts_on'
        );
        $stays = [];
        foreach ($rows as $row) {
            $ends = $row['ends_on'] ?? null;
            $stays[] = [
                'boothId' => RowValue::string($row['booth_id'] ?? null),
                'vendorName' => RowValue::string($row['vendor_name'] ?? null),
                'startsOn' => RowValue::string($row['starts_on'] ?? null),
                'endsOn' => is_string($ends) && $ends !== '' ? $ends : null,
            ];
        }

        return $stays;
    }
}
