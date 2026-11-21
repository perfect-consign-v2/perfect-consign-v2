<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use InvalidArgumentException;

final class ReportCatalog
{
    /**
     * @var list<string>
     */
    private const RANGES = ['today', 'yesterday', 'this_week', 'this_month', 'last_month'];

    /**
     * @var list<string>
     */
    private const SALES_GROUPS = ['day', 'tender', 'vendor', 'cashier', 'category'];

    public function accept(string $name, string $sheet, string $grouped, string $range): void
    {
        $this->name($name);
        if ($sheet === 'sales' && $grouped === 'hour' && $range !== 'today') {
            throw new InvalidArgumentException('An hourly report is one day.');
        }
        if (!$this->pair($sheet, $grouped, $range)) {
            throw new InvalidArgumentException($this->why($sheet, $grouped));
        }
    }

    private function name(string $name): void
    {
        $trimmed = trim($name);
        if ($trimmed === '' || strlen($trimmed) > 80 || strtolower($trimmed) === 'sales tax') {
            throw new InvalidArgumentException('Choose a name. Sales tax stays on its own page.');
        }
    }

    private function pair(string $sheet, string $grouped, string $range): bool
    {
        if ($this->salesPair($sheet, $grouped, $range)) {
            return true;
        }
        if ($this->asOf($sheet) && $grouped === 'none' && $range === 'now') {
            return true;
        }

        return $this->history($sheet) && $grouped === 'none' && in_array($range, self::RANGES, true);
    }

    private function salesPair(string $sheet, string $grouped, string $range): bool
    {
        if ($sheet !== 'sales') {
            return false;
        }
        if ($grouped === 'hour') {
            return $range === 'today';
        }

        return in_array($grouped, self::SALES_GROUPS, true) && in_array($range, self::RANGES, true);
    }

    private function why(string $sheet, string $grouped): string
    {
        if ($sheet === 'sales' && !in_array($grouped, self::SALES_GROUPS, true) && $grouped !== 'hour') {
            return 'That report does not use that grouping.';
        }
        if ($sheet === 'sales') {
            return 'That report does not use that range.';
        }
        if ($grouped !== 'none') {
            return 'That report does not use that grouping.';
        }

        return 'That report does not use that range.';
    }

    private function asOf(string $sheet): bool
    {
        return $sheet === 'rent' || $sheet === 'stored_value' || $sheet === 'expiring';
    }

    private function history(string $sheet): bool
    {
        return $sheet === 'payouts' || $sheet === 'donated' || $sheet === 'sell_through';
    }
}
