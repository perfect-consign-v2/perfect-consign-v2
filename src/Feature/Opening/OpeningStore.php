<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Opening;

use PDO;

final class OpeningStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function openingAllowed(): bool
    {
        $value = $this->pdo->query(
            "SELECT count(*) FROM journals AS other
             WHERE other.posting_key <> 'opening'
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS opened
                   WHERE opened.id = other.reverses_journal_id
                     AND opened.posting_key = 'opening'
               )"
        );
        if ($value === false) {
            return false;
        }
        $others = (int) $value->fetchColumn();
        $live = $this->pdo->query(
            "SELECT count(*) FROM journals AS prior
             WHERE prior.posting_key = 'opening'
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = prior.id
               )"
        );
        if ($live === false) {
            return false;
        }

        return $others === 0 && (int) $live->fetchColumn() === 0;
    }
}
