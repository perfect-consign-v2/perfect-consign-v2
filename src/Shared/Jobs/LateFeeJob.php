<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\LateFeeDue;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;

final class LateFeeJob
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lateFees(string $date): void
    {
        $statements = new Statements($this->pdo);
        $fees = new LateFee(
            new LedgerStore($this->pdo, $statements, new LedgerPeriods($this->pdo), new JournalCash($this->pdo)),
            $statements
        );
        (new LateFeeDue($statements, new MallPayoutSettings($statements), $fees))->apply($date);
    }
}
