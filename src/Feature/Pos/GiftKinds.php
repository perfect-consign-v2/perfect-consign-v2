<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;

final class GiftKinds
{
    public static function normalize(string $kind): string
    {
        if (!in_array($kind, ['gift_certificate', 'gift_card', 'store_credit'], true)) {
            throw new DatabaseRefusal('Choose a gift certificate, a gift card, or store credit.');
        }

        return $kind;
    }

    public static function fromSource(string $source): ?string
    {
        return match ($source) {
            'gift_certificate_issue' => 'gift_certificate',
            'gift_card_issue' => 'gift_card',
            'store_credit_issue' => 'store_credit',
            default => null,
        };
    }

    public static function prefix(string $kind): string
    {
        return match ($kind) {
            'gift_card' => 'GD',
            'store_credit' => 'SC',
            default => 'GC',
        };
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'gift_card' => 'Gift card',
            'store_credit' => 'Store credit',
            default => 'Gift certificate',
        };
    }
}
