<?php

declare(strict_types=1);

final class ExportJournalFile
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function write(
        string $system,
        string $fromDate,
        string $toDate,
        string $outputPath,
        string $taxRate,
    ): void {
        if ($system !== 'quickbooks' && $system !== 'xero') {
            throw new InvalidArgumentException('the export system must be quickbooks or xero');
        }
        if ($outputPath === '') {
            throw new InvalidArgumentException('an export path is required');
        }

        $statement = $this->pdo->prepare(
            'SELECT stall_export_journal_csv(:system, :from_date, :to_date, :tax_rate)'
        );
        $statement->execute([
            ':system' => $system,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
            ':tax_rate' => $taxRate,
        ]);
        $lines = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($lines === []) {
            throw new RuntimeException('the export returned no lines');
        }

        $directory = dirname($outputPath);
        if ($directory !== '' && !is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the export directory');
        }

        $handle = fopen($outputPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the export file');
        }

        try {
            foreach ($lines as $line) {
                if (!is_string($line)) {
                    throw new RuntimeException('the export returned a non-text line');
                }
                $payload = $line . "\n";
                $offset = 0;
                $length = strlen($payload);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($payload, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Could not write the export file');
                    }
                    $offset += $written;
                }
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the export file');
            }
        } catch (Throwable $error) {
            fclose($handle);
            if (is_file($outputPath) && !unlink($outputPath)) {
                throw new RuntimeException('Could not remove the incomplete export file', 0, $error);
            }
            throw $error;
        }

        if (!fclose($handle)) {
            if (is_file($outputPath) && !unlink($outputPath)) {
                throw new RuntimeException('Could not remove the incomplete export file');
            }
            throw new RuntimeException('Could not close the export file');
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $system = $argv[1] ?? '';
    $fromDate = $argv[2] ?? '';
    $toDate = $argv[3] ?? '';
    $outputPath = $argv[4] ?? '';
    $taxRate = $argv[5] ?? 'Tax Exempt';

    $host = getenv('STALL_DB_HOST');
    $port = getenv('STALL_DB_PORT');
    $name = getenv('STALL_DB_NAME');
    $user = getenv('STALL_DB_USER');
    $password = getenv('STALL_DB_PASSWORD');
    if (
        !is_string($host) || $host === ''
        || !is_string($port) || $port === ''
        || !is_string($name) || $name === ''
        || !is_string($user) || $user === ''
        || !is_string($password) || $password === ''
    ) {
        fwrite(STDERR, "STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
        exit(1);
    }

    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        (new ExportJournalFile($pdo))->write($system, $fromDate, $toDate, $outputPath, $taxRate);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
