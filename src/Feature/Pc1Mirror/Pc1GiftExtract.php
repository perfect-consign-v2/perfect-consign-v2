<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

final class Pc1GiftExtract
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(PDO $pdo): array
    {
        return array_merge(
            $this->table($pdo, 'gift_cards', 'gift_card', 'gift_card_id', 'code'),
            $this->table($pdo, 'gift_certificates', 'gift_certificate', 'gift_certificate_id', 'code'),
            $this->table($pdo, 'store_credits', 'store_credit', 'store_credit_id', 'store_credit_id'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function table(PDO $pdo, string $table, string $kind, string $idColumn, string $numberColumn): array
    {
        $this->assertIdentifiers($table, $idColumn, $numberColumn);
        $exists = $pdo->query('SELECT to_regclass(' . $pdo->quote($table) . ')');
        if ($exists === false || $exists->fetchColumn() === null) {
            return [];
        }
        $numberExpr = $numberColumn === 'store_credit_id'
            ? 'left(replace(' . $idColumn . '::text, \'-\', \'\'), 12)'
            : $numberColumn;
        $rows = $pdo->query(
            'SELECT ' . $idColumn . '::text AS pc1_id,
                    ' . $numberExpr . '::text AS number,
                    COALESCE(balance, 0)::text AS balance
             FROM ' . $table . "
             WHERE COALESCE(status, 'active') = 'active'
               AND COALESCE(balance, 0) > 0"
        );

        return $this->mapRows($rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [], $kind);
    }

    private function assertIdentifiers(string $table, string $idColumn, string $numberColumn): void
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1 || preg_match('/^[a-z_]+$/', $idColumn) !== 1) {
            throw new MirrorException('gift table name is invalid');
        }
        if ($numberColumn !== 'store_credit_id' && preg_match('/^[a-z_]+$/', $numberColumn) !== 1) {
            throw new MirrorException('gift number column is invalid');
        }
    }

    /**
     * @param array<mixed> $listed
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $listed, string $kind): array
    {
        $out = [];
        foreach ($listed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'pc1_id' => MirrorFields::text($row['pc1_id'] ?? null),
                'kind' => $kind,
                'number' => $this->code(MirrorFields::text($row['number'] ?? null)),
                'balance_minor' => Pc1Money::dollarsToCents(MirrorFields::text($row['balance'] ?? null, '0')),
            ];
        }

        return $out;
    }

    private function code(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'X';
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $trimmed) ?? 'X';

        return substr($safe, 0, 40);
    }
}
