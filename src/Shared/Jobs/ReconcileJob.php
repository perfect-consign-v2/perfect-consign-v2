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
        $statement->execute([$report->displayedTotal(), $report->lineTotal()]);
    }

    public function tie(string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $found) !== 1) {
            return;
        }
        $from = $found[1] . '-' . $found[2] . '-01';
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if (!$start instanceof \DateTimeImmutable) {
            return;
        }
        $to = $start->format('Y-m-t');
        $tax = $this->pdo->prepare('SELECT stall_refuse_tax_drift(?::date, ?::date)');
        $tax->execute([$from, $to]);
        $role = $this->pdo->prepare('SELECT stall_refuse_role_drift(?)');
        foreach (['gift_card', 'gift_certificate', 'store_credit', 'booth_rent_receivable', 'sales_tax_payable'] as $name) {
            $role->execute([$name]);
        }
    }
}
