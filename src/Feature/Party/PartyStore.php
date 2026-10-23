<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;

class PartyStore
{
    public function __construct(private readonly Statements $statements)
    {
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

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(): array
    {
        return $this->statements->rows(
            'SELECT assignment.id, assignment.booth_id, assignment.vendor_id, booth.code AS booth_code,
                    vendor.code AS vendor_code, assignment.starts_on, assignment.ends_on
             FROM booth_assignments AS assignment
             JOIN booths AS booth ON booth.id = assignment.booth_id
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             ORDER BY assignment.starts_on, booth.code'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendorRows(): array
    {
        return $this->statements->rows(
            'SELECT vendor.id AS vendor_id, vendor.code AS vendor_code, vendor.name AS vendor_name,
                    vendor.first_name, vendor.last_name, vendor.phone, vendor.email, vendor.address, vendor.active,
                    assignment.id AS assignment_id, assignment.booth_id, booth.code AS booth_code,
                    assignment.starts_on, assignment.ends_on, rent.amount_minor AS rent_minor
             FROM vendors AS vendor
             LEFT JOIN booth_assignments AS assignment ON assignment.vendor_id = vendor.id
             LEFT JOIN booths AS booth ON booth.id = assignment.booth_id
             LEFT JOIN assignment_rent AS rent ON rent.assignment_id = assignment.id
             ORDER BY vendor.name, vendor.code, assignment.starts_on'
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
                $this->setAskingRent($id, $amount);
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

    public function addAssignment(
        string $boothId,
        string $vendorId,
        string $startsOn,
        string $endsOn,
        ?int $rentMinor = null,
    ): void {
        $this->statements->transaction(function () use ($boothId, $vendorId, $startsOn, $endsOn, $rentMinor): void {
            $sql = $endsOn === ''
                ? 'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date) RETURNING id'
                : 'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, ?::date, ?::date) RETURNING id';
            $params = $endsOn === ''
                ? [$boothId, $vendorId, $startsOn]
                : [$boothId, $vendorId, $startsOn, $endsOn];
            $rows = $this->statements->bound($sql, $params);
            $id = $rows[0]['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException('that assignment was not created');
            }
            if ($rentMinor === null) {
                throw new \InvalidArgumentException('that booth has no asking rent');
            }
            $this->statements->change(
                'INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)',
                [$id, $rentMinor]
            );
        });
    }

    public function askingRent(string $boothId): ?int
    {
        $rows = $this->statements->bound(
            'SELECT amount_minor FROM booth_rent_rates WHERE booth_id = ?::uuid',
            [$boothId]
        );
        $amount = $rows[0]['amount_minor'] ?? null;
        if ($amount === null || $amount === '') {
            return null;
        }

        return \PerfectConsign\Shared\Scalar\RowValue::integer($amount);
    }

    public function endAssignment(string $id, string $endsOn): void
    {
        $this->statements->change(
            'UPDATE booth_assignments SET ends_on = ?::date WHERE id = ?::uuid',
            [$endsOn, $id]
        );
    }

    public function setAskingRent(string $boothId, int $amount): void
    {
        $this->statements->change(
            'INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES (?::uuid, ?)
             ON CONFLICT (booth_id) DO UPDATE SET amount_minor = EXCLUDED.amount_minor',
            [$boothId, $amount]
        );
    }
}
