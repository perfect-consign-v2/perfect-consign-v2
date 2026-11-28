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
     * @return array{wait_days: string, deposit_covers_rent: bool, deposits_on: bool}
     */
    public function current(): array
    {
        $rows = $this->statements->rows(
            'SELECT write_off_wait_days::text AS wait_days, deposit_covers_rent::text AS covers, booth_deposits_enabled::text AS deposits FROM mall'
        );
        $row = $rows[0] ?? [];

        return [
            'wait_days' => RowValue::optionalString($row['wait_days'] ?? null) ?? '90',
            'deposit_covers_rent' => ($row['covers'] ?? null) === 'true',
            'deposits_on' => ($row['deposits'] ?? null) === 'true',
        ];
    }

    public function save(string $waitDays, bool $depositCoversRent, string $tenantId): void
    {
        $days = trim($waitDays);
        if (preg_match('/^[0-9]{1,4}$/', $days) !== 1 || (int) $days > 3650) {
            throw new \InvalidArgumentException('Enter the days as a whole number from 0 to 3650.');
        }
        $this->statements->transaction(function () use ($days, $depositCoversRent, $tenantId): void {
            $this->statements->change(
                'UPDATE mall SET write_off_wait_days = ?::integer, deposit_covers_rent = ?::boolean',
                [$days, PgBool::literal($depositCoversRent)]
            );
            $this->audit->record('settings.mall', 'mall', $tenantId);
        });
    }
}
