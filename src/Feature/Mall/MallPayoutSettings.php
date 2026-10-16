<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MallPayoutSettings
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function operatingMode(): string
    {
        return RowValue::string($this->row()['operating_mode'] ?? null);
    }

    public function payoutCadence(): string
    {
        return RowValue::string($this->row()['payout_cadence'] ?? null);
    }

    public function rentPrepaid(): bool
    {
        return $this->flag('rent_prepaid_from_prior_sales');
    }

    public function rentalsEnabled(): bool
    {
        return $this->flag('booth_rentals_enabled');
    }

    public function cycleDay(): int
    {
        $day = RowValue::integer($this->row()['rent_cycle_day'] ?? null);

        return $day >= 1 && $day <= 28 ? $day : 1;
    }

    public function cycleFrozen(): bool
    {
        $rows = $this->statements->rows(
            "SELECT 1
             FROM journals AS posted
             WHERE posted.source_type = 'rent_charge'
               AND NOT posted.is_reversal
               AND NOT EXISTS (
                   SELECT 1
                   FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = posted.id
               )
             LIMIT 1"
        );

        return $rows !== [];
    }

    public function save(string $operatingMode, bool $rentPrepaid, bool $rentalsEnabled, int $cycleDay): void
    {
        $mode = in_array($operatingMode, ['consignor', 'booth_mall', 'both'], true) ? $operatingMode : 'both';
        $day = $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;
        $this->statements->change(
            'UPDATE mall
             SET operating_mode = ?, payout_cadence = ?, rent_prepaid_from_prior_sales = ?::boolean,
                 booth_rentals_enabled = ?::boolean, rent_cycle_day = ?',
            [$mode, 'monthly', $rentPrepaid ? 'true' : 'false', $rentalsEnabled ? 'true' : 'false', $day]
        );
    }

    /**
     * @return array{enabled: bool, grace: int, first: int, next: int}
     */
    public function lateFee(): array
    {
        $days = RowValue::integer($this->row()['late_fee_grace_days'] ?? null);

        return [
            'enabled' => $this->flag('late_fees_enabled'),
            'grace' => $days >= 0 && $days <= 28 ? $days : 0,
            'first' => max(0, RowValue::integer($this->row()['late_fee_first_minor'] ?? null)),
            'next' => max(0, RowValue::integer($this->row()['late_fee_next_minor'] ?? null)),
        ];
    }

    public function saveLateFees(bool $enabled, int $grace, int $first, int $next): void
    {
        $days = $grace >= 0 && $grace <= 28 ? $grace : 0;
        $this->statements->change(
            'UPDATE mall
             SET late_fees_enabled = ?::boolean, late_fee_grace_days = ?,
                 late_fee_first_minor = ?, late_fee_next_minor = ?',
            [$enabled ? 'true' : 'false', $days, max(0, $first), max(0, $next)]
        );
    }

    private function flag(string $column): bool
    {
        $value = $this->row()[$column] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->statements->rows(
            'SELECT operating_mode, payout_cadence, rent_prepaid_from_prior_sales,
                    booth_rentals_enabled, rent_cycle_day, late_fees_enabled, late_fee_grace_days,
                    late_fee_first_minor, late_fee_next_minor
             FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no payout settings');
        }

        return $row;
    }
}
