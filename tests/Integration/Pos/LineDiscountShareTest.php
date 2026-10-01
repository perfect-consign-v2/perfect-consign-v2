<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PDO;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class LineDiscountShareTest extends TestCase
{
    public function testThePreviewUsesTheLedgerShare(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ticketId = $this->id($pdo);
        $sessionId = $this->id($pdo);
        $registerId = $this->id($pdo);
        $vendorId = $this->id($pdo);
        $firstId = $this->id($pdo);
        $secondId = $this->id($pdo);
        $code = 'share' . substr(str_replace('-', '', $ticketId), -12);
        $open = false;
        try {
            $statements->change(
                'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
                [$registerId, 'Share proof ' . $code]
            );
            $statements->change(
                'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
                 VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0)',
                [$sessionId, $registerId, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
            );
            $open = true;
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, $code, 'Share', 'Proof']
            );
            $this->item($statements, $firstId, $vendorId, $code . 'a', 300, 'BC-' . $code . 'a');
            $this->item($statements, $secondId, $vendorId, $code . 'b', 700, 'BC-' . $code . 'b');
            $statements->change(
                'INSERT INTO tickets (id, session_id) VALUES (?::uuid, ?::uuid)',
                [$ticketId, $sessionId]
            );
            $statements->change(
                'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
                 VALUES (?::uuid, 1, ?::uuid, 300), (?::uuid, 2, ?::uuid, 700)',
                [$ticketId, $firstId, $ticketId, $secondId]
            );
            $lines = (new TicketPricing($statements))->lines($ticketId, 0, [
                'kind' => 'amount',
                'value' => 100,
                'reason' => 'share',
                'waiver' => '',
            ]);
            self::assertCount(2, $lines);
            self::assertSame($this->share($statements, $ticketId, $firstId), $lines[0]['discount']);
            self::assertSame($this->share($statements, $ticketId, $secondId), $lines[1]['discount']);
            self::assertNotSame($lines[0]['discount'], $lines[1]['discount']);
        } finally {
            $statements->change('DELETE FROM tickets WHERE id = ?::uuid', [$ticketId]);
            if ($open) {
                $statements->change(
                    'UPDATE register_sessions
                     SET closing_counts = opening_counts,
                         counted_cash_minor = 0,
                         counted_card_minor = 0,
                         counted_checks_minor = 0,
                         closed_at = clock_timestamp()
                     WHERE id = ?::uuid AND closed_at IS NULL',
                    [$sessionId]
                );
            }
        }
    }

    private function share(Statements $statements, string $ticketId, string $itemId): int
    {
        $rows = $statements->bound(
            'SELECT stall_line_discount(?::uuid, ?::uuid, ?, ?) AS share',
            [$ticketId, $itemId, 'amount', 100]
        );

        return RowValue::integer($rows[0]['share'] ?? null);
    }

    private function item(
        Statements $statements,
        string $itemId,
        string $vendorId,
        string $code,
        int $price,
        string $barcode,
    ): void {
        $statements->change(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
             VALUES (?::uuid, ?, ?::uuid, ?, ?, ?, ?::date, 1, 0)',
            [$itemId, $code, $vendorId, 'Shared coat', $price, $barcode, '2026-06-15']
        );
    }

    private function id(PDO $pdo): string
    {
        $statement = $pdo->query('SELECT uuidv7() AS id');
        if ($statement === false) {
            self::fail('could not create an id');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['id'] ?? null)) {
            self::fail('could not create an id');
        }

        return $row['id'];
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
