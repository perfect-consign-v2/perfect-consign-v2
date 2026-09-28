<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\Money;

final class TicketAdjustment
{
    /** @var list<string> */
    public const DISCOUNT_REASONS = ['manager approval', 'damaged', 'loyalty', 'promotion', 'other'];

    /** @var list<string> */
    public const WAIVER_REASONS = ['resale', 'charity', 'government or school', 'other'];

    /**
     * @return array{kind: ?string, value: ?int, reason: ?string}
     */
    public static function discount(string $action, string $kind, string $value, string $reason): array
    {
        if ($action === 'remove' || self::blank($value)) {
            return ['kind' => null, 'value' => null, 'reason' => null];
        }
        if ($kind !== 'percent' && $kind !== 'amount') {
            throw new \InvalidArgumentException('choose percent or amount');
        }
        if (!in_array($reason, self::DISCOUNT_REASONS, true)) {
            throw new \InvalidArgumentException('a discount needs a reason');
        }
        $points = $kind === 'percent' ? self::basis($value) : Money::parseMinor($value);
        if ($points === 0) {
            return ['kind' => null, 'value' => null, 'reason' => null];
        }

        return ['kind' => $kind, 'value' => $points, 'reason' => $reason];
    }

    public static function waiver(string $action, string $reason): ?string
    {
        if ($action === 'restore') {
            return null;
        }
        if (!in_array($reason, self::WAIVER_REASONS, true)) {
            throw new \InvalidArgumentException('a tax waiver needs a reason');
        }

        return $reason;
    }

    private static function blank(string $value): bool
    {
        return $value === '' || $value === '0' || $value === '0.0' || $value === '0.00';
    }

    private static function basis(string $value): int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new \InvalidArgumentException('enter a percent from 0 to 100');
        }
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $points = ((int) $matches[1] * 100) + (int) $fraction;
        if ($points > 10000) {
            throw new \InvalidArgumentException('enter a percent from 0 to 100');
        }

        return $points;
    }
}
