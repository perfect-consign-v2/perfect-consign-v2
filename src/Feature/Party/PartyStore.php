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
        return $this->statements->rows('SELECT id, code, name FROM vendors ORDER BY code');
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
            'SELECT assignment.id, booth.code AS booth_code, vendor.code AS vendor_code,
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
    public function rates(): array
    {
        return $this->statements->rows(
            'SELECT booth.code, rate.month_start, rate.amount_minor
             FROM booth_rent_rates AS rate
             JOIN booths AS booth ON booth.id = rate.booth_id
             ORDER BY rate.month_start, booth.code'
        );
    }

    public function addVendor(string $code, string $name): void
    {
        $this->statements->change('INSERT INTO vendors (code, name) VALUES (?, ?)', [$code, $name]);
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
