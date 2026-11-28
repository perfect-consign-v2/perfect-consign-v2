<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The store's bad debt rules, kept on the mall row: how many days past due a
 * debt must be before it can be written off, and whether a held booth deposit
 * must be applied to unpaid rent first.
 */
final class BadDebtRules
{
    public function __construct(
        private readonly Statements $statements,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @return array{wait_days: string, deposit_covers_rent: bool, deposits_on: bool, dormancy_days: string}
     */
    public function current(): array
    {
        $rows = $this->statements->rows(
            'SELECT write_off_wait_days::text AS wait_days, deposit_covers_rent::text AS covers, booth_deposits_enabled::text AS deposits,
                    unclaimed_dormancy_days::text AS dormancy_days
             FROM mall'
        );
        $row = $rows[0] ?? [];

        return [
            'wait_days' => RowValue::optionalString($row['wait_days'] ?? null) ?? '90',
            'deposit_covers_rent' => ($row['covers'] ?? null) === 'true',
            'deposits_on' => ($row['deposits'] ?? null) === 'true',
            'dormancy_days' => RowValue::optionalString($row['dormancy_days'] ?? null) ?? '1095',
        ];
    }

    public function save(string $waitDays, bool $depositCoversRent, string $dormancyDays, string $tenantId): void
    {
        $days = $this->days($waitDays);
        $dormancy = $this->days($dormancyDays);
        $this->statements->transaction(function () use ($days, $depositCoversRent, $dormancy, $tenantId): void {
            $this->statements->change(
                'UPDATE mall SET write_off_wait_days = ?::integer, deposit_covers_rent = ?::boolean, unclaimed_dormancy_days = ?::integer',
                [$days, PgBool::literal($depositCoversRent), $dormancy]
            );
            $this->audit->record('settings.mall', 'mall', $tenantId);
        });
    }

    private function days(string $typed): string
    {
        $days = trim($typed);
        if (preg_match('/^[0-9]{1,4}$/', $days) !== 1 || (int) $days > 3650) {
            throw new \InvalidArgumentException('Enter the days as a whole number from 0 to 3650.');
        }

        return $days;
    }
}
