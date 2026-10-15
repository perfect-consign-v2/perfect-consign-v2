<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Offer;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class OfferStore
{
    private readonly OfferFields $fields;

    public function __construct(private readonly Statements $statements)
    {
        $this->fields = new OfferFields($statements);
    }

    /**
     * @return list<array{id: string, name: string, scope: string, kind: string, threshold: int, deal_minor: int, funded_by: string, starts_on: string, ends_on: string, scope_label: string, ended: bool}>
     */
    public function offers(?string $vendorId, string $today): array
    {
        $sql = 'SELECT offer.id, offer.name, offer.scope, offer.kind, offer.threshold_qty, offer.deal_minor,
                       offer.funded_by, offer.starts_on::text AS starts_on, offer.ends_on::text AS ends_on,
                       COALESCE(item.code, vendor.code, \'Store\') AS scope_label
                FROM offers AS offer
                LEFT JOIN items AS item ON offer.scope = \'item\' AND item.id = offer.scope_id
                LEFT JOIN vendors AS vendor ON offer.scope = \'vendor\' AND vendor.id = offer.scope_id';
        if ($vendorId === null) {
            $rows = $this->statements->rows($sql . ' ORDER BY offer.starts_on DESC, offer.name');
        } else {
            $rows = $this->statements->bound(
                $sql . ' WHERE (offer.scope = \'vendor\' AND offer.scope_id = ?::uuid)
                            OR (offer.scope = \'item\' AND item.vendor_id = ?::uuid)
                         ORDER BY offer.starts_on DESC, offer.name',
                [$vendorId, $vendorId]
            );
        }
        $offers = [];
        foreach ($rows as $row) {
            $ends = $row['ends_on'] ?? null;
            $offers[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'scope' => RowValue::string($row['scope'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
                'threshold' => RowValue::integer($row['threshold_qty'] ?? null),
                'deal_minor' => RowValue::integer($row['deal_minor'] ?? null),
                'funded_by' => RowValue::string($row['funded_by'] ?? null),
                'starts_on' => RowValue::string($row['starts_on'] ?? null),
                'ends_on' => is_string($ends) ? $ends : '',
                'scope_label' => RowValue::string($row['scope_label'] ?? null),
                'ended' => is_string($ends) && $ends < $today,
            ];
        }

        return $offers;
    }

    /**
     * @return array{id: string, name: string, scope: string, kind: string, threshold: int, deal_minor: int, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string}|null
     */
    public function find(string $id, ?string $vendorId): ?array
    {
        $rows = $this->statements->bound(
            'SELECT offer.id, offer.name, offer.scope, offer.kind, offer.threshold_qty, offer.deal_minor,
                    offer.funded_by, offer.starts_on::text AS starts_on, offer.ends_on::text AS ends_on,
                    offer.scope_id::text AS scope_id, item.code AS item_code, item.vendor_id::text AS item_vendor
             FROM offers AS offer
             LEFT JOIN items AS item ON offer.scope = \'item\' AND item.id = offer.scope_id
             WHERE offer.id = ?::uuid',
            [$id]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $scope = RowValue::string($row['scope'] ?? null);
        $scopeId = is_string($row['scope_id'] ?? null) ? $row['scope_id'] : '';
        if (!$this->visible($scope, $scopeId, is_string($row['item_vendor'] ?? null) ? $row['item_vendor'] : '', $vendorId)) {
            return null;
        }
        $ends = $row['ends_on'] ?? null;

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'scope' => $scope,
            'kind' => RowValue::string($row['kind'] ?? null),
            'threshold' => RowValue::integer($row['threshold_qty'] ?? null),
            'deal_minor' => RowValue::integer($row['deal_minor'] ?? null),
            'funded_by' => RowValue::string($row['funded_by'] ?? null),
            'starts_on' => RowValue::string($row['starts_on'] ?? null),
            'ends_on' => is_string($ends) ? $ends : '',
            'item_code' => is_string($row['item_code'] ?? null) ? $row['item_code'] : '',
            'vendor_id' => $scope === 'vendor' ? $scopeId : '',
        ];
    }

    /**
     * @return list<array{id: string, code: string, name: string}>
     */
    public function vendors(): array
    {
        $rows = $this->statements->rows('SELECT id, code, name FROM vendors ORDER BY code');
        $vendors = [];
        foreach ($rows as $row) {
            $vendors[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
            ];
        }

        return $vendors;
    }

    /**
     * @param array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string} $fields
     */
    public function save(?string $id, array $fields, ?string $vendorId): void
    {
        $offer = $this->fields->checked($fields, $vendorId);
        if ($id !== null && $this->find($id, $vendorId) === null) {
            throw new InvalidArgumentException('That deal is not yours.');
        }
        $ends = $offer['ends_on'] ?? '';
        $scopeId = $offer['scope_id'] ?? '';
        if ($id === null) {
            $this->statements->change(
                'INSERT INTO offers (name, scope, scope_id, kind, threshold_qty, deal_minor, funded_by, starts_on, ends_on)
                 VALUES (?, ?, NULLIF(?, \'\')::uuid, ?, ?, ?, ?, ?::date, NULLIF(?, \'\')::date)',
                [$offer['name'], $offer['scope'], $scopeId, $offer['kind'], $offer['threshold'], $offer['deal_minor'], $offer['funded_by'], $offer['starts_on'], $ends]
            );

            return;
        }
        $updated = $this->statements->affected(
            'UPDATE offers
             SET name = ?, scope = ?, scope_id = NULLIF(?, \'\')::uuid, kind = ?, threshold_qty = ?, deal_minor = ?,
                 funded_by = ?, starts_on = ?::date, ends_on = NULLIF(?, \'\')::date
             WHERE id = ?::uuid',
            [$offer['name'], $offer['scope'], $scopeId, $offer['kind'], $offer['threshold'], $offer['deal_minor'], $offer['funded_by'], $offer['starts_on'], $ends, $id]
        );
        if ($updated !== 1) {
            throw new InvalidArgumentException('That deal is not yours.');
        }
    }

    public function end(string $id, string $today, ?string $vendorId): void
    {
        if ($this->find($id, $vendorId) === null) {
            throw new InvalidArgumentException('That deal is not yours.');
        }
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $this->statements->change(
            'UPDATE offers SET starts_on = LEAST(starts_on, ?::date), ends_on = ?::date WHERE id = ?::uuid',
            [$yesterday, $yesterday, $id]
        );
    }

    private function visible(string $scope, string $scopeId, string $itemVendor, ?string $vendorId): bool
    {
        if ($vendorId === null) {
            return true;
        }
        if ($scope === 'vendor') {
            return $scopeId === $vendorId;
        }

        return $scope === 'item' && $itemVendor === $vendorId;
    }
}
