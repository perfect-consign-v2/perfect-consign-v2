<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketDrafts
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{
     *     id: string,
     *     draft_no: int,
     *     vendor_id: string,
     *     vendor_label: string,
     *     description: string,
     *     price_text: string,
     *     brand_id: string,
     *     brand_label: string,
     *     discount_kind: string,
     *     discount_value: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     missing: list<string>
     * }>
     */
    public function forSession(string $sessionId): array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, draft_no, vendor_id::text AS vendor_id, vendor_label,
                    description, price_text, brand_id::text AS brand_id, brand_label,
                    discount_kind, discount_value, attributes_json
             FROM ticket_draft_lines
             WHERE session_id = ?::uuid
             ORDER BY draft_no',
            [$sessionId]
        );
        $list = [];
        foreach ($rows as $row) {
            $vendorId = RowValue::optionalString($row['vendor_id'] ?? null) ?? '';
            $description = RowValue::string($row['description'] ?? null);
            $priceText = RowValue::string($row['price_text'] ?? null);
            $list[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'draft_no' => RowValue::integer($row['draft_no'] ?? null),
                'vendor_id' => $vendorId,
                'vendor_label' => RowValue::string($row['vendor_label'] ?? null),
                'description' => $description,
                'price_text' => $priceText,
                'brand_id' => RowValue::optionalString($row['brand_id'] ?? null) ?? '',
                'brand_label' => RowValue::string($row['brand_label'] ?? null),
                'discount_kind' => RowValue::string($row['discount_kind'] ?? null),
                'discount_value' => RowValue::string($row['discount_value'] ?? null),
                'attributes' => $this->decodeAttributes(RowValue::string($row['attributes_json'] ?? null)),
                'missing' => $this->missingFields($vendorId, $description, $priceText),
            ];
        }

        return $list;
    }

    public function hasAny(string $sessionId): bool
    {
        $rows = $this->statements->bound(
            'SELECT 1 AS present FROM ticket_draft_lines WHERE session_id = ?::uuid LIMIT 1',
            [$sessionId]
        );

        return $rows !== [];
    }

    /**
     * @param array<string, array{id: string, label: string}> $attributes
     */
    public function park(
        string $sessionId,
        string $vendorId,
        string $vendorLabel,
        string $description,
        string $priceText,
        string $brandId,
        string $brandLabel,
        string $discountKind,
        string $discountValue,
        array $attributes,
    ): int {
        $draftNo = $this->nextNo($sessionId);
        $this->statements->change(
            'INSERT INTO ticket_draft_lines (
                session_id, draft_no, vendor_id, vendor_label, description, price_text,
                brand_id, brand_label, discount_kind, discount_value, attributes_json
             ) VALUES (
                ?::uuid, ?, ?::uuid, ?, ?, ?,
                ?::uuid, ?, ?, ?, ?
             )',
            [
                $sessionId,
                $draftNo,
                $vendorId !== '' ? $vendorId : null,
                $vendorLabel,
                $description,
                $priceText,
                $brandId !== '' ? $brandId : null,
                $brandLabel,
                $discountKind !== '' ? $discountKind : 'consignor_default',
                $discountValue,
                json_encode($attributes, JSON_THROW_ON_ERROR),
            ]
        );

        return $draftNo;
    }

    public function remove(string $sessionId, int $draftNo): void
    {
        $this->statements->change(
            'DELETE FROM ticket_draft_lines WHERE session_id = ?::uuid AND draft_no = ?',
            [$sessionId, $draftNo]
        );
    }

    public function clear(string $sessionId): void
    {
        $this->statements->change(
            'DELETE FROM ticket_draft_lines WHERE session_id = ?::uuid',
            [$sessionId]
        );
    }

    /**
     * @return list<string>
     */
    public function missingFields(string $vendorId, string $description, string $priceText): array
    {
        $missing = [];
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            $missing[] = 'vendor';
        }
        if (trim($description) === '') {
            $missing[] = 'description';
        }
        if (trim($priceText) === '') {
            $missing[] = 'price';
        }

        return $missing;
    }

    private function nextNo(string $sessionId): int
    {
        $rows = $this->statements->bound(
            'SELECT COALESCE(max(draft_no), 0) + 1 AS next_no FROM ticket_draft_lines WHERE session_id = ?::uuid',
            [$sessionId]
        );

        return RowValue::integer($rows[0]['next_no'] ?? null);
    }

    /**
     * @return array<string, array{id: string, label: string}>
     */
    private function decodeAttributes(string $json): array
    {
        if ($json === '' || $json === '{}') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $attributes = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            $attributes[$key] = [
                'id' => is_string($value['id'] ?? null) ? $value['id'] : '',
                'label' => is_string($value['label'] ?? null) ? $value['label'] : '',
            ];
        }

        return $attributes;
    }
}
