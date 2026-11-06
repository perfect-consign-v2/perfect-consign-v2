<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Shared\Scalar\RowValue;

final class MirrorProver
{
    /**
     * @return list<string>
     */
    public function prove(MirrorMall $mall, MirrorArtifact $artifact): array
    {
        $balances = MirrorFields::stringKeyed($artifact->readJson('balances.json'));
        $idMap = MirrorFields::stringKeyed($artifact->readJson('id-map.json'));
        $vendors = $this->stringMap($idMap['vendors'] ?? null);
        $booths = $this->stringMap($idMap['booths'] ?? null);
        $failures = [];
        $this->checkMapped($mall, $vendors, $this->rows($balances['vendor_payable'] ?? null), AccountMap::VENDOR_PAYABLE, 'vendor', true, 'pc1_id', 'vendor payable', $failures);
        $this->checkMapped($mall, $vendors, $this->rows($balances['vendor_receivable'] ?? null), AccountMap::VENDOR_RECEIVABLE, 'vendor', false, 'pc1_id', 'vendor receivable', $failures);
        $this->checkMapped(
            $mall,
            $booths,
            $this->aggregateBoothRent($this->rows($balances['booth_rent'] ?? null)),
            AccountMap::BOOTH_RENT,
            'booth',
            false,
            'pc1_booth_id',
            'booth rent',
            $failures
        );
        $this->checkTotal($mall, AccountMap::SALES_TAX, true, MirrorFields::cents($balances['sales_tax_payable_cents'] ?? 0), 'sales tax', $failures);
        $this->checkTotal($mall, AccountMap::CASH, false, MirrorFields::cents($balances['cash_cents'] ?? 0), 'cash', $failures);
        foreach (
            [
                'gift_certificate' => AccountMap::GIFT_CERTIFICATE,
                'gift_card' => AccountMap::GIFT_CARD,
                'store_credit' => AccountMap::STORE_CREDIT,
            ] as $key => $role
        ) {
            $this->checkTotal($mall, $role, true, MirrorFields::cents($balances[$key . '_cents'] ?? 0), $key, $failures);
        }
        $vendorCount = $mall->statements->rows('SELECT count(*)::int AS n FROM vendors');
        $expectedVendors = MirrorFields::cents($balances['vendor_count'] ?? count($vendors));
        $actualVendors = RowValue::integer($vendorCount[0]['n'] ?? 0);
        if ($actualVendors !== $expectedVendors && $actualVendors !== count($vendors)) {
            $failures[] = 'vendor count expected ' . $expectedVendors . ' got ' . $actualVendors;
        }

        return $failures;
    }

    /**
     * @param array<string, string> $map
     * @param list<array<string, mixed>> $rows
     * @param list<string> $failures
     */
    private function checkMapped(
        MirrorMall $mall,
        array $map,
        array $rows,
        string $role,
        string $type,
        bool $creditMinusDebit,
        string $idKey,
        string $label,
        array &$failures,
    ): void {
        foreach ($rows as $row) {
            $pc1 = MirrorFields::text($row[$idKey] ?? null);
            $expected = MirrorFields::cents($row['cents'] ?? 0);
            $pc2 = $map[$pc1] ?? null;
            if ($pc2 === null) {
                $failures[] = $label . ' missing map for ' . $pc1;
                continue;
            }
            $actual = $this->sumRole($mall, $role, $type, $pc2, $creditMinusDebit);
            if ($actual !== $expected) {
                $failures[] = $label . ' ' . $pc1 . ' expected ' . $expected . ' got ' . $actual;
            }
        }
    }

    /**
     * @param list<string> $failures
     */
    private function checkTotal(
        MirrorMall $mall,
        string $role,
        bool $creditMinusDebit,
        int $expected,
        string $label,
        array &$failures,
    ): void {
        $actual = $this->sumAccount($mall, $role, $creditMinusDebit);
        if ($actual !== $expected) {
            $failures[] = $label . ' expected ' . $expected . ' got ' . $actual;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function aggregateBoothRent(array $rows): array
    {
        $byBooth = [];
        foreach ($rows as $row) {
            $pc1 = MirrorFields::text($row['pc1_booth_id'] ?? null);
            if ($pc1 === '') {
                continue;
            }
            $byBooth[$pc1] = ($byBooth[$pc1] ?? 0) + MirrorFields::cents($row['cents'] ?? 0);
        }
        $out = [];
        foreach ($byBooth as $pc1 => $cents) {
            if ($cents < 1) {
                continue;
            }
            $out[] = ['pc1_booth_id' => $pc1, 'cents' => $cents];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $mapped) {
            if (is_string($key) && is_string($mapped)) {
                $out[$key] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = MirrorFields::stringKeyed($row);
            }
        }

        return $out;
    }

    private function sumRole(MirrorMall $mall, string $role, string $type, string $ref, bool $creditMinusDebit): int
    {
        $expr = $creditMinusDebit
            ? 'COALESCE(sum(line.credit_minor - line.debit_minor), 0)'
            : 'COALESCE(sum(line.debit_minor - line.credit_minor), 0)';
        $rows = $mall->statements->bound(
            "SELECT {$expr} AS total
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = ?
               AND line.subledger_type = ?
               AND line.subledger_ref = ?",
            [$role, $type, $ref]
        );

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    private function sumAccount(MirrorMall $mall, string $role, bool $creditMinusDebit): int
    {
        $expr = $creditMinusDebit
            ? 'COALESCE(sum(line.credit_minor - line.debit_minor), 0)'
            : 'COALESCE(sum(line.debit_minor - line.credit_minor), 0)';
        $rows = $mall->statements->bound(
            "SELECT {$expr} AS total
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = ?",
            [$role]
        );

        return RowValue::integer($rows[0]['total'] ?? 0);
    }
}
