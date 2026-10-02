<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PDO;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class GiftCertificateNumberTest extends TestCase
{
    public function testALowercaseNumberDoesNotWedgeTheNextCertificate(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo));
        $gifts = new GiftIssue($statements, $ledger);
        $registerId = $ledger->newId();
        $sessionId = $ledger->newId();
        [$lower, $upper, $expected] = $this->pair($statements);
        $pdo->beginTransaction();
        try {
            $statements->change(
                'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
                [$registerId, 'Certificate proof']
            );
            $statements->change(
                'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
                 VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0)',
                [$sessionId, $registerId, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
            );
            $statements->change(
                "INSERT INTO stored_values (kind, number) VALUES ('gift_certificate', ?), ('gift_certificate', ?)",
                [$lower, $upper]
            );
            $journalId = $gifts->issue($sessionId, $this->openDate($statements), '', 100, 'cash', null);
            $journal = $ledger->journal($journalId);
            self::assertNotNull($journal);
            self::assertSame($expected, $journal['source_reference'] ?? null);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function pair(Statements $statements): array
    {
        $rows = $statements->rows(
            "SELECT upper(number) AS number
             FROM stored_values
             WHERE kind = 'gift_certificate' AND upper(number) ~ '^GC-[0-9]+$'
             ORDER BY char_length(number) DESC, upper(number) DESC
             LIMIT 1"
        );
        $top = '';
        if ($rows !== [] && is_string($rows[0]['number'] ?? null)) {
            $top = $rows[0]['number'];
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $digits = '9';
            for ($place = 0; $place < 11; $place++) {
                $digits .= (string) random_int(0, 9);
            }
            $stem = substr($digits, 0, 11);
            $lower = 'gc-' . $stem . '1';
            $upper = 'GC-' . $stem . '5';
            $expected = 'GC-' . $stem . '6';
            if ($top === '' || strlen($upper) > strlen($top) || strcasecmp($upper, $top) > 0) {
                return [$lower, $upper, $expected];
            }
        }
        self::fail('could not choose a certificate number past the current sequence');
    }

    private function openDate(Statements $statements): string
    {
        $rows = $statements->rows(
            "SELECT to_char(starts_on, 'YYYY-MM-DD') AS starts_on
             FROM accounting_periods
             WHERE status = 'open'
             ORDER BY starts_on
             LIMIT 1"
        );
        $date = '';
        if ($rows !== [] && is_string($rows[0]['starts_on'] ?? null)) {
            $date = $rows[0]['starts_on'];
        }
        if ($date === '') {
            return '2096-03-01';
        }

        return $date;
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        if ($listed === false) {
            self::fail('webprobe schema is missing');
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }

        return $schema;
    }

    private function connection(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is required');
        }

        return $value;
    }
}
