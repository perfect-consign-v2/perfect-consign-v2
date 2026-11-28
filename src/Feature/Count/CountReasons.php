<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Count;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The store's reasons for a lower count, and how long a piece left behind
 * waits before the store may keep it. The database keeps at least one reason
 * switched on and refuses deleting a reason the history uses.
 */
final class CountReasons
{
    public function __construct(
        private readonly Statements $statements,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, kind: string, who: string, active: bool, used: bool}>
     */
    public function all(): array
    {
        $rows = $this->statements->rows(
            "SELECT reason.id::text AS id, reason.label, reason.kind,
                    CASE WHEN reason.vendor_may AND reason.staff_may THEN 'both'
                         WHEN reason.staff_may THEN 'staff'
                         ELSE 'vendor' END AS who,
                    reason.active::text AS active,
                    EXISTS (SELECT 1 FROM item_quantity_changes AS change WHERE change.reason_id = reason.id)::text AS used
             FROM quantity_reasons AS reason
             ORDER BY reason.position, lower(reason.label)"
        );
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'label' => RowValue::string($row['label'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
                'who' => RowValue::string($row['who'] ?? null),
                'active' => RowValue::string($row['active'] ?? null) === 'true',
                'used' => RowValue::string($row['used'] ?? null) === 'true',
            ];
        }

        return $listed;
    }

    public function add(string $label, string $kind, string $who): void
    {
        [$vendorMay, $staffMay] = $this->who($who);
        $this->statements->transaction(function () use ($label, $kind, $vendorMay, $staffMay): void {
            $this->refuseTaken($label, '');
            $rows = $this->statements->bound(
                'INSERT INTO quantity_reasons (label, kind, vendor_may, staff_may, position)
                 SELECT ?, ?, ?::boolean, ?::boolean, COALESCE(max(position), 0) + 1 FROM quantity_reasons
                 RETURNING id::text AS id',
                [$this->label($label), $this->kind($kind), PgBool::literal($vendorMay), PgBool::literal($staffMay)]
            );
            $this->audit->record('quantity_reason.create', 'quantity_reason', RowValue::string($rows[0]['id'] ?? null));
        });
    }

    public function save(string $id, string $label, string $kind, string $who): void
    {
        [$vendorMay, $staffMay] = $this->who($who);
        $this->refuseTaken($label, $id);
        $this->changed($id, 'UPDATE quantity_reasons SET label = ?, kind = ?, vendor_may = ?::boolean, staff_may = ?::boolean WHERE id = ?::uuid', [
            $this->label($label), $this->kind($kind), PgBool::literal($vendorMay), PgBool::literal($staffMay), $id,
        ]);
    }

    public function setActive(string $id, bool $active): void
    {
        $this->changed($id, 'UPDATE quantity_reasons SET active = ?::boolean WHERE id = ?::uuid', [PgBool::literal($active), $id]);
    }

    public function remove(string $id): void
    {
        $this->changed($id, 'DELETE FROM quantity_reasons WHERE id = ?::uuid', [$id]);
    }

    public function waitDays(): string
    {
        $rows = $this->statements->rows('SELECT COALESCE(abandon_wait_days::text, \'\') AS days FROM mall');

        return RowValue::optionalString($rows[0]['days'] ?? null) ?? '';
    }

    public function saveWaitDays(string $days, string $tenantId): void
    {
        $text = trim($days);
        if ($text !== '' && (preg_match('/^[1-9][0-9]{0,2}$/', $text) !== 1 || (int) $text > 365)) {
            throw new \InvalidArgumentException('Enter the days as a whole number from 1 to 365, or leave it empty.');
        }
        $this->statements->transaction(function () use ($text, $tenantId): void {
            $this->statements->change('UPDATE mall SET abandon_wait_days = NULLIF(?, \'\')::smallint', [$text]);
            $this->audit->record('settings.mall', 'mall', $tenantId);
        });
    }

    /**
     * @param list<mixed> $params
     */
    private function changed(string $id, string $sql, array $params): void
    {
        $this->statements->transaction(function () use ($id, $sql, $params): void {
            if ($this->statements->affected($sql, $params) !== 1) {
                throw new \InvalidArgumentException('That reason was not found.');
            }
            $this->audit->record('quantity_reason.edit', 'quantity_reason', $id);
        });
    }

    private function refuseTaken(string $label, string $id): void
    {
        $rows = $this->statements->bound(
            'SELECT 1 FROM quantity_reasons WHERE lower(label) = lower(?) AND id::text <> ?',
            [trim($label), $id]
        );
        if ($rows !== []) {
            throw new \InvalidArgumentException('That reason is already on the list.');
        }
    }

    private function label(string $label): string
    {
        $text = trim($label);
        if ($text === '' || mb_strlen($text) > 60) {
            throw new \InvalidArgumentException('Enter the reason in 60 letters or fewer.');
        }

        return $text;
    }

    private function kind(string $kind): string
    {
        if ($kind !== 'leaving' && $kind !== 'correction') {
            throw new \InvalidArgumentException('Choose whether the piece left the store or it was a correction.');
        }

        return $kind;
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function who(string $who): array
    {
        return match ($who) {
            'both' => [true, true],
            'staff' => [false, true],
            default => throw new \InvalidArgumentException('Choose who may use this reason.'),
        };
    }
}
