<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

final class RentForm
{
    /**
     * @return list<string>
     */
    public function checked(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return $ids;
    }

    public function filled(string $value): bool
    {
        return $value !== '' && $value !== '0' && $value !== '0.0' && $value !== '0.00';
    }

    public function chosen(string $query, string $posted): string
    {
        if ($query !== '') {
            return $query;
        }

        return $posted;
    }

    /**
     * @param list<string> $waived
     */
    public function needsDrawer(array $waived, string $cash, string $card, string $checks, string $amount): bool
    {
        return $waived === []
            || $this->filled($cash)
            || $this->filled($card)
            || $this->filled($checks)
            || $this->filled($amount);
    }
}
