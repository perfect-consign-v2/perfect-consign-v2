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

    /**
     * @return array{rentals: bool, consignment: bool}
     */
    public static function capabilities(string $operatingMode): array
    {
        return match ($operatingMode) {
            'consignor' => ['rentals' => false, 'consignment' => true],
            'booth_mall' => ['rentals' => true, 'consignment' => false],
            default => ['rentals' => true, 'consignment' => true],
        };
    }

    public static function getPostedConsignment(string $operatingMode, string $field): string
    {
        if ($operatingMode === '') {
            return $field;
        }
        if (self::capabilities($operatingMode)['consignment']) {
            return '1';
        }

        return '0';
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

    /**
     * @return array{consignment: bool, store: bool}
     */
    public function getShop(): array
    {
        return [
            'consignment' => $this->flag('consignment_enabled'),
            'store' => $this->flag('store_stock_enabled'),
        ];
    }

    /**
     * @return array{consignment: bool, store: bool, days?: int|null}
     */
    public function getPostedShop(string $flag, string $consignment, string $store, ?int $days = null): array
    {
        $current = $this->getShop();
        if ($flag !== '1') {
            return $current + ['days' => $days];
        }
        $enabled = $consignment === '1';

        return [
            'consignment' => $enabled,
            'store' => $store === '1',
            'days' => $this->getDaysWhenConsigned($days, $enabled ? '1' : ''),
        ];
    }

    public function getDaysWhenConsigned(?int $days, string $posted): ?int
    {
        if (!$this->flag('consignment_enabled') && $posted !== '1') {
            return null;
        }

        return $days;
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

    public function save(
        string $operatingMode,
        bool $rentPrepaid,
        bool $rentalsEnabled,
        int $cycleDay,
        bool $consignmentEnabled,
        bool $storeStockEnabled,
    ): void {
        $mode = in_array($operatingMode, ['consignor', 'booth_mall', 'both'], true) ? $operatingMode : 'both';
        $day = $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;
        $this->statements->change(
            'UPDATE mall
             SET operating_mode = ?, payout_cadence = ?, rent_prepaid_from_prior_sales = ?::boolean,
                 booth_rentals_enabled = ?::boolean, rent_cycle_day = ?,
                 consignment_enabled = ?::boolean, store_stock_enabled = ?::boolean',
            [
                $mode,
                'monthly',
                $rentPrepaid ? 'true' : 'false',
                $rentalsEnabled ? 'true' : 'false',
                $day,
                $consignmentEnabled ? 'true' : 'false',
                $storeStockEnabled ? 'true' : 'false',
            ]
        );
    }

    /**
     * @return array{enabled: bool, grace: int, first: int, next: int, effective: string}
     */
    public function lateFee(): array
    {
        $days = RowValue::integer($this->row()['late_fee_grace_days'] ?? null);
        $effective = $this->row()['late_fee_effective_on'] ?? null;

        return [
            'enabled' => $this->flag('late_fees_enabled'),
            'grace' => $days >= 0 && $days <= 28 ? $days : 0,
            'first' => max(0, RowValue::integer($this->row()['late_fee_first_minor'] ?? null)),
            'next' => max(0, RowValue::integer($this->row()['late_fee_next_minor'] ?? null)),
            'effective' => is_string($effective) && preg_match('/^\d{4}-\d{2}-\d{2}/', $effective) === 1
                ? substr($effective, 0, 10)
                : '',
        ];
    }

    public function saveLateFees(bool $enabled, int $grace, int $first, int $next): void
    {
        $days = $grace >= 0 && $grace <= 28 ? $grace : 0;
        $current = $this->lateFee();
        $effective = null;
        if ($enabled) {
            $effective = $current['enabled'] && $current['effective'] !== '' ? $current['effective'] : date('Y-m-d');
        }
        $this->statements->change(
            'UPDATE mall
             SET late_fees_enabled = ?::boolean, late_fee_grace_days = ?,
                 late_fee_first_minor = ?, late_fee_next_minor = ?,
                 late_fee_effective_on = ?::date',
            [$enabled ? 'true' : 'false', $days, max(0, $first), max(0, $next), $effective]
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
                    booth_rentals_enabled, rent_cycle_day, consignment_enabled, store_stock_enabled,
                    late_fees_enabled, late_fee_grace_days, late_fee_first_minor, late_fee_next_minor,
                    late_fee_effective_on
             FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no payout settings');
        }

        return $row;
    }
}
