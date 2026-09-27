<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;

final class PartyStore
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
            'SELECT id, code, name, width_ft, depth_ft, x_ft, z_ft FROM booths ORDER BY code'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(): array
    {
        return $this->statements->rows(
            'SELECT assignment.id, assignment.vendor_id, booth.code AS booth_code, vendor.code AS vendor_code,
                    assignment.starts_on, assignment.ends_on
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
                    assignment.starts_on, assignment.ends_on
             FROM vendors AS vendor
             LEFT JOIN booth_assignments AS assignment ON assignment.vendor_id = vendor.id
             LEFT JOIN booths AS booth ON booth.id = assignment.booth_id
             ORDER BY vendor.name, vendor.code, assignment.starts_on'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rates(): array
    {
        return $this->statements->rows(
            'SELECT booth.id AS booth_id, booth.code, rate.month_start, rate.amount_minor
             FROM booth_rent_rates AS rate
             JOIN booths AS booth ON booth.id = rate.booth_id
             ORDER BY rate.month_start, booth.code'
        );
    }

    public function addVendor(
        string $code,
        string $firstName,
        string $lastName,
        string $phone,
        string $email,
        string $address,
    ): string {
        $rows = $this->statements->bound(
            'INSERT INTO vendors (code, first_name, last_name, phone, email, address) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $code,
                $this->personName($firstName, 'first'),
                $this->personName($lastName, 'last'),
                $this->contact($phone, 'phone'),
                $this->contact($email, 'email'),
                $this->contact($address, 'address'),
            ]
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
            'SELECT id, code, first_name, last_name, name, phone, email, address, active FROM vendors WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function saveVendor(
        string $id,
        string $code,
        string $firstName,
        string $lastName,
        string $phone,
        string $email,
        string $address,
    ): void {
        $count = $this->statements->affected(
            'UPDATE vendors SET code = ?, first_name = ?, last_name = ?, phone = ?, email = ?, address = ? WHERE id = ?::uuid',
            [
                $code,
                $this->personName($firstName, 'first'),
                $this->personName($lastName, 'last'),
                $this->contact($phone, 'phone'),
                $this->contact($email, 'email'),
                $this->contact($address, 'address'),
                $id,
            ]
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
            'SELECT id, code, name, width_ft, depth_ft, x_ft, z_ft FROM booths WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function addBooth(string $code, string $name, ?int $width, ?int $depth, ?int $x, ?int $z): void
    {
        $this->statements->change(
            'INSERT INTO booths (code, name, width_ft, depth_ft, x_ft, z_ft) VALUES (?, ?, ?, ?, ?, ?)',
            [$code, $name, $width, $depth, $x, $z]
        );
    }

    public function saveBooth(string $id, string $code, string $name, ?int $width, ?int $depth, ?int $x, ?int $z): void
    {
        $count = $this->statements->affected(
            'UPDATE booths SET code = ?, name = ?, width_ft = ?, depth_ft = ?, x_ft = ?, z_ft = ? WHERE id = ?::uuid',
            [$code, $name, $width, $depth, $x, $z, $id]
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

    public function addAssignment(string $boothId, string $vendorId, string $startsOn, string $endsOn): void
    {
        if ($endsOn === '') {
            $this->statements->change(
                'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date)',
                [$boothId, $vendorId, $startsOn]
            );
            return;
        }
        $this->statements->change(
            'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, ?::date, ?::date)',
            [$boothId, $vendorId, $startsOn, $endsOn]
        );
    }

    public function endAssignment(string $id, string $endsOn): void
    {
        $this->statements->change(
            'UPDATE booth_assignments SET ends_on = ?::date WHERE id = ?::uuid',
            [$endsOn, $id]
        );
    }

    private function personName(string $value, string $part): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('a ' . $part . ' name is required');
        }

        return $trimmed;
    }

    private function contact(string $value, string $kind): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if ($kind === 'phone' && strlen($trimmed) > 40) {
            throw new \InvalidArgumentException('that phone is too long');
        }
        if ($kind === 'email' && (strlen($trimmed) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $trimmed) !== 1)) {
            throw new \InvalidArgumentException('that email is not an email address');
        }
        if ($kind === 'address' && strlen($trimmed) > 400) {
            throw new \InvalidArgumentException('that address is too long');
        }

        return $trimmed;
    }

    public function addRate(string $boothId, string $monthStart, int $amount): void
    {
        if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $monthStart, $matches) === 1) {
            $monthStart = $matches[1] . '-01';
        }
        $this->statements->change(
            'INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES (?::uuid, ?::date, ?)',
            [$boothId, $monthStart, $amount]
        );
    }
}
