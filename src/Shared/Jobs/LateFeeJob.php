<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\LateFeeDue;
use PerfectConsign\Feature\Rent\RentPaidAhead;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;

final class LateFeeJob
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lateFees(string $date): void
    {
        $statements = new Statements($this->pdo);
        $postings = new PostingFunctions($this->pdo, new LedgerPeriods($this->pdo), new JournalCash($this->pdo));
        (new RentPaidAhead($statements, $postings))->apply($date);
        (new LateFeeDue($statements, $postings, new LateFee($postings)))->apply($date);
    }
}
