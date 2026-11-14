<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Export;

use PDO;
use PDOException;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Csv;

final class ExportStore
{
    private string $buffer = 'php://temp';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function useBuffer(string $buffer): void
    {
        $this->buffer = $buffer;
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
        $fields = $this->protectedFields($line);
        if ($fields === null) {
            return $line;
        }

        return $this->writeFields($fields);
    }

    /**
     * @return list<string|null>|null
     */
    private function protectedFields(string $line): ?array
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
            return null;
        }

        return $fields;
    }

    /**
     * @param list<string|null> $fields
     */
    private function writeFields(array $fields): string
    {
        $handle = fopen($this->buffer, 'w+');
        if ($handle === false) {
            throw new DatabaseRefusal('the export returned a non-text line');
        }
        try {
            $written = $this->putCsv($handle, $fields);
        } finally {
            fclose($handle);
        }
        if (!is_string($written) || $written === '') {
            throw new DatabaseRefusal('the export returned a non-text line');
        }

        return rtrim($written, "\n");
    }

    /**
     * @param resource $handle
     * @param list<string|null> $fields
     */
    private function putCsv($handle, array $fields): string
    {
        $csv = fputcsv($handle, $fields, ',', '"', '', "\n");
        if ($csv === false || $csv === 0) {
            throw new DatabaseRefusal('the export returned a non-text line');
        }
        rewind($handle);
        $written = stream_get_contents($handle);
        if (!is_string($written)) {
            throw new DatabaseRefusal('the export returned a non-text line');
        }

        return $written;
    }
}
