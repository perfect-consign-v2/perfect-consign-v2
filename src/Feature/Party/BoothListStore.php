<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Catalog\CatalogFlag;
use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;

/**
 * Holder, open rent, and the rows the kernel will not let the list delete.
 */
final class BoothListStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rates(): array
    {
        return $this->statements->rows(
            'SELECT booth.id AS booth_id, booth.code, rate.amount_minor
             FROM booth_rent_rates AS rate
             JOIN booths AS booth ON booth.id = rate.booth_id
             ORDER BY booth.code'
        );
    }

    /**
     * @return array{
     *   stays: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>,
     *   blocked: list<array<string, mixed>>
     * }
     */
    public function facts(): array
    {
        return [
            'stays' => $this->statements->rows(
                'SELECT assignment.booth_id, assignment.vendor_id, vendor.name AS vendor_name,
                        vendor.code AS vendor_code, assignment.starts_on, assignment.ends_on
                 FROM booth_assignments AS assignment
                 JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
                 ORDER BY assignment.starts_on'
            ),
            'open_rent' => $this->statements->rows(
                "SELECT stay.booth_id::text AS booth_id,
                        sum(line.debit_minor - line.credit_minor) AS open_minor
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 JOIN booth_assignments AS stay ON stay.id::text = line.subledger_ref
                 WHERE role.role = 'booth_rent_receivable'
                   AND line.subledger_type = 'assignment'
                 GROUP BY stay.booth_id
                 HAVING sum(line.debit_minor - line.credit_minor) > 0"
            ),
            'blocked' => $this->statements->rows(
                "SELECT id FROM booths
                 WHERE EXISTS (
                     SELECT 1 FROM booth_assignments WHERE booth_id = booths.id
                 ) OR EXISTS (
                     SELECT 1 FROM booth_rent_rates WHERE booth_id = booths.id
                 )"
            ),
        ];
    }

    public function engaged(string $id, string $today): bool
    {
        $rows = $this->statements->bound(
            'SELECT 1 FROM booth_assignments
             WHERE booth_id = ?::uuid
               AND (ends_on IS NULL OR ends_on >= ?::date)
             LIMIT 1',
            [$id, $today]
        );

        return $rows !== [];
    }

    public function setActive(string $id, bool $active, string $today): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('that booth is not in this mall');
        }
        if (!$active && $this->engaged($id, $today)) {
            throw new \InvalidArgumentException(
                'Cannot set booth to inactive while it has a current or scheduled assignment. End assignments first.'
            );
        }
        $count = $this->statements->affected(
            'UPDATE booths SET active = ?::boolean WHERE id = ?::uuid',
            [PgBool::literal($active), $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that booth is not in this mall');
        }
    }

    /**
     * @param array<string, mixed>|null $booth
     * @return array{show: bool, active: bool, retire: bool}
     */
    public function standing(?array $booth, string $today): array
    {
        $id = '';
        if (is_array($booth) && is_string($booth['id'] ?? null)) {
            $id = $booth['id'];
        }
        if ($id === '' || !is_array($booth)) {
            return ['show' => false, 'active' => true, 'retire' => false];
        }
        $active = CatalogFlag::on($booth['active'] ?? true);

        return [
            'show' => true,
            'active' => $active,
            'retire' => $active && !$this->engaged($id, $today),
        ];
    }
}
