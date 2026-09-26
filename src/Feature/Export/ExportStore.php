<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Export;

use PDO;
use PDOException;
use PerfectConsign\Ledger\DatabaseRefusal;

final class ExportStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exportCsv(string $system, string $from, string $to, string $taxRate): string
    {
        try {
            $statement = $this->pdo->prepare('SELECT stall_export_journal_csv(?, ?::date, ?::date, ?)');
            $statement->execute([$system, $from, $to, $taxRate]);
            $lines = $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
        $csv = '';
        foreach ($lines as $line) {
            if (!is_string($line)) {
                throw new DatabaseRefusal('the export returned a non-text line');
            }
            $csv .= $line . "\n";
        }

        return $csv;
    }
}
