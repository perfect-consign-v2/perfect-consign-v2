<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Report\BooksReport;
use PerfectConsign\Shared\Database\Statements;

final class ReconcileJob
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reconcile(): void
    {
        $report = new BooksReport(new Statements($this->pdo));
        $statement = $this->pdo->prepare('SELECT stall_refuse_report_drift(?, ?)');
        $statement->execute([$report->viewTotal(), $report->lineTotal()]);
    }
}
