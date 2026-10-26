<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\Statements;

class PartyStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly JournalAudit $audit,
        private readonly AssignmentBook $assignments,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendors(): array
    {
        return $this->statements->rows(
            'SELECT id, code, first_name, last_name, name, phone, email, address FROM vendors ORDER BY name, code'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function booths(): array
    {
        return $this->statements->rows(
            'SELECT id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id FROM booths ORDER BY code'
        );
    }

    public function addVendor(VendorDraft $vendor): string
    {
        $rows = $this->statements->bound(
            'INSERT INTO vendors (
                code, first_name, last_name, phone, email, address,
                commission_bps, agreement_starts_on, agreement_ends_on, tax_id, notes,
                tax_exempt, tax_exempt_number, business_name,
                discount_by, keep_days, expiry_action
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?::date, ?::date, ?, ?, ?::boolean, ?, ?, ?, ?, ?) RETURNING id',
            $vendor->values()
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException('the vendor was not saved');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVendor(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, code, first_name, last_name, name, business_name, phone, email, address, active,
                    commission_bps, agreement_starts_on, agreement_ends_on, tax_id, notes,
                    tax_exempt, tax_exempt_number, payout_mode,
                    discount_by, keep_days, expiry_action, email_bounced
             FROM vendors WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function saveVendor(string $id, VendorDraft $vendor): void
    {
        $count = $this->statements->affected(
            "UPDATE vendors
             SET code = ?, first_name = ?, last_name = ?, phone = ?, email = ?, address = ?,
                 commission_bps = ?, agreement_starts_on = ?::date, agreement_ends_on = ?::date,
                 tax_id = ?, notes = ?, tax_exempt = ?::boolean, tax_exempt_number = ?, business_name = ?,
                 discount_by = ?, keep_days = ?, expiry_action = ?,
                 email_bounced = CASE
                     WHEN lower(COALESCE(email, '')) IS DISTINCT FROM lower(COALESCE(?::text, '')) THEN false
                     ELSE email_bounced
                 END
             WHERE id = ?::uuid",
            [...$vendor->values(), $vendor->email(), $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
        $this->audit->record('vendor.edit', $id, 'vendor');
    }

    public function setPayoutMode(string $id, string $mode): void
    {
        $stored = in_array($mode, ['pay', 'credit_only', 'hold'], true) ? $mode : 'pay';
        $count = $this->statements->affected(
            'UPDATE vendors SET payout_mode = ? WHERE id = ?::uuid',
            [$stored, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
    }

    public function setActive(string $id, bool $active): void
    {
        $count = $this->statements->affected(
            'UPDATE vendors SET active = ?::boolean WHERE id = ?::uuid',
            [$active ? 'true' : 'false', $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
    }

    public function removeVendor(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM vendors WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBooth(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id FROM booths WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function addBooth(
        string $code,
        string $name,
        ?int $width,
        ?int $depth,
        ?int $amount = null,
    ): void {
        $insert = function () use ($code, $name, $width, $depth, $amount): void {
            $rows = $this->statements->bound(
                'INSERT INTO booths (code, name, width_ft, depth_ft) VALUES (?, ?, ?, ?) RETURNING id',
                [$code, $name, $width, $depth]
            );
            $id = $rows[0]['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException('that booth was not created');
            }
            if ($amount !== null) {
                $this->assignments->setAskingRent($id, $amount);
            }
        };
        if ($amount === null) {
            $insert();

            return;
        }
        $this->statements->transaction($insert);
    }

    public function saveBooth(string $id, string $code, string $name, ?int $width, ?int $depth): void
    {
        $count = $this->statements->affected(
            'UPDATE booths SET code = ?, name = ?, width_ft = ?, depth_ft = ? WHERE id = ?::uuid',
            [$code, $name, $width, $depth, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that booth is not in this mall');
        }
    }

    public function removeBooth(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM booths WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('that booth is not in this mall');
        }
    }
}
