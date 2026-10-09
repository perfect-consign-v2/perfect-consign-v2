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
        $value = $this->row()['rent_prepaid_from_prior_sales'] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    public function save(string $operatingMode, bool $rentPrepaid): void
    {
        $mode = in_array($operatingMode, ['consignor', 'booth_mall', 'both'], true) ? $operatingMode : 'both';
        $this->statements->change(
            'UPDATE mall SET operating_mode = ?, payout_cadence = ?, rent_prepaid_from_prior_sales = ?::boolean',
            [$mode, 'monthly', $rentPrepaid ? 'true' : 'false']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->statements->rows(
            'SELECT operating_mode, payout_cadence, rent_prepaid_from_prior_sales FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no payout settings');
        }

        return $row;
    }
}
