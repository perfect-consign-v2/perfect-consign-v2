<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PDO;

final class RentStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function rate(string $boothId, string $date): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT amount_minor FROM booth_rent_rates
             WHERE booth_id = ?::uuid AND month_start = date_trunc('month', ?::date)::date"
        );
        $statement->execute([$boothId, $date]);
        $amount = $statement->fetchColumn();
        if ($amount === false) {
            return null;
        }

        return (int) $amount;
    }
}
