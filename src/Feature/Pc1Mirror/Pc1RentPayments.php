<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

final class Pc1RentPayments
{
    /**
     * @return array{
     *     tagged: array<string, list<array{period_start: string, period_end: string, amount_cents: int}>>,
     *     untagged: array<string, list<array{period_start: string, period_end: string, amount_cents: int}>>
     * }
     */
    public function load(PDO $pdo): array
    {
        $deductions = $pdo->query(
            'SELECT consignor_id::text AS consignor_id,
                    assignment_id::text AS assignment_id,
                    period_start::text AS period_start,
                    period_end::text AS period_end,
                    amount::text AS amount
             FROM rent_deductions'
        );
        $deductionRows = $deductions !== false ? $deductions->fetchAll(PDO::FETCH_ASSOC) : [];
        $tagged = [];
        $untagged = [];
        foreach ($deductionRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payment = [
                'period_start' => MirrorFields::text($row['period_start'] ?? null),
                'period_end' => MirrorFields::text($row['period_end'] ?? null),
                'amount_cents' => Pc1Money::dollarsToCents(MirrorFields::text($row['amount'] ?? null, '0')),
            ];
            $assignmentId = MirrorFields::text($row['assignment_id'] ?? null);
            if ($assignmentId !== '') {
                $tagged[$assignmentId][] = $payment;
                continue;
            }
            $untagged[MirrorFields::text($row['consignor_id'] ?? null)][] = $payment;
        }

        return ['tagged' => $tagged, 'untagged' => $untagged];
    }
}
