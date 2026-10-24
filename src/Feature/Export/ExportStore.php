<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Export;

use PDO;
use PDOException;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Csv;

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
            $csv .= $this->guarded($line) . "\n";
        }

        return $csv;
    }

    private function guarded(string $line): string
    {
        $fields = str_getcsv($line, ',', '"', '');
        $changed = false;
        foreach ($fields as $index => $field) {
            if (!is_string($field)) {
                continue;
            }
            $safe = Csv::protect($field);
            if ($safe === $field) {
                continue;
            }
            $fields[$index] = $safe;
            $changed = true;
        }
        if (!$changed) {
            return $line;
        }
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new DatabaseRefusal('the export returned a non-text line');
        }
        try {
            if (fputcsv($handle, $fields, ',', '"', '', "\n") === false) {
                throw new DatabaseRefusal('the export returned a non-text line');
            }
            rewind($handle);
            $written = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if (!is_string($written)) {
            throw new DatabaseRefusal('the export returned a non-text line');
        }

        return rtrim($written, "\n");
    }
}
