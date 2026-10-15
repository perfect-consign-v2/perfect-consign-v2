<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Offer;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class OfferFields
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @param array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string} $fields
     * @return array{name: string, scope: string, scope_id: ?string, kind: string, threshold: int, deal_minor: int, funded_by: string, starts_on: string, ends_on: ?string}
     */
    public function checked(array $fields, ?string $vendorId): array
    {
        $name = $this->name($fields['name']);
        $scope = $this->choice($fields['scope'], ['item', 'vendor', 'store'], 'Choose an item, a vendor, or the whole store.');
        $kind = $this->choice($fields['kind'], ['bundle', 'unit_break'], 'Choose N for a price, or N or more at a unit price.');
        $funded = $this->payer($vendorId, $fields['funded_by'], $scope);
        [$starts, $ends] = $this->span($fields['starts_on'], $fields['ends_on']);

        return [
            'name' => $name,
            'scope' => $scope,
            'scope_id' => $this->scopeId($scope, $fields['item_code'], $fields['vendor_id'], $vendorId),
            'kind' => $kind,
            'threshold' => $this->quantity($fields['threshold']),
            'deal_minor' => Money::parseMinor($fields['price']),
            'funded_by' => $funded,
            'starts_on' => $starts,
            'ends_on' => $ends,
        ];
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new InvalidArgumentException('Enter a deal name of 80 characters or less.');
        }

        return $name;
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(string $value, array $allowed, string $message): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function quantity(string $threshold): int
    {
        if (preg_match('/^[1-9]\d*$/', $threshold) !== 1) {
            throw new InvalidArgumentException('The quantity has to be a whole number.');
        }
        $count = (int) $threshold;
        if ($count < 2) {
            throw new InvalidArgumentException('A quantity deal starts at two.');
        }

        return $count;
    }

    private function payer(?string $vendorId, string $funded, string $scope): string
    {
        $funded = $vendorId === null ? $funded : 'vendor';
        if (!in_array($funded, ['vendor', 'store'], true)) {
            throw new InvalidArgumentException('Choose who pays for the deal.');
        }
        if ($vendorId !== null && $scope === 'store') {
            throw new InvalidArgumentException('A vendor deal stays on that vendor\'s items.');
        }

        return $funded;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function span(string $startsOn, string $endsOn): array
    {
        $starts = $this->day($startsOn, 'The start');
        $ends = trim($endsOn) === '' ? null : $this->day($endsOn, 'The end');
        if ($ends !== null && $ends < $starts) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }

        return [$starts, $ends];
    }

    private function scopeId(string $scope, string $itemCode, string $vendorChoice, ?string $vendorId): ?string
    {
        if ($scope === 'store') {
            return null;
        }
        if ($scope === 'vendor') {
            $chosen = $vendorId ?? $vendorChoice;
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $chosen) !== 1) {
                throw new InvalidArgumentException('Choose the vendor.');
            }

            return $chosen;
        }
        $rows = $this->statements->bound(
            'SELECT id, vendor_id FROM items WHERE lower(code) = lower(?)',
            [trim($itemCode)]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new InvalidArgumentException('That item is not on the floor.');
        }
        $owner = RowValue::string($row['vendor_id'] ?? null);
        if ($vendorId !== null && $owner !== $vendorId) {
            throw new InvalidArgumentException('That item is not yours.');
        }

        return RowValue::string($row['id'] ?? null);
    }

    private function day(string $value, string $label): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' needs a date.');
        }

        return $value;
    }
}
