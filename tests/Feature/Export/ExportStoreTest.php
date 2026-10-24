<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Export;

use PDO;
use PDOException;
use PDOStatement;
use PerfectConsign\Feature\Export\ExportStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PHPUnit\Framework\TestCase;

final class ExportStoreTest extends TestCase
{
    public function testANonTextLineIsRefused(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([1]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the export returned a non-text line');
        (new ExportStore($pdo))->exportCsv('quickbooks', '2026-01-01', '2026-01-31', '0');
    }

    public function testADatabaseErrorIsRethrown(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('SQLSTATE[08006] down'));

        $this->expectException(DatabaseRefusal::class);
        (new ExportStore($pdo))->exportCsv('quickbooks', '2026-01-01', '2026-01-31', '0');
    }

    public function testAFormulaFieldIsPrefixedAndASignedNumberIsNot(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn(['Ada,=1+1', 'Ada,-500']);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $csv = (new ExportStore($pdo))->exportCsv('quickbooks', '2026-01-01', '2026-01-31', '0');

        self::assertStringContainsString("'=1+1", $csv);
        self::assertStringContainsString('Ada,-500', $csv);
    }
}
