<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PDO;
use PerfectConsign\Feature\Inventory\ItemPageWindow;
use PerfectConsign\Feature\Party\PartyPageQuery;
use PerfectConsign\Feature\Party\PartyPageRead;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

final class PartyPageReadTest extends TestCase
{
    public function testEveryPartyCanBeReachedOnePageAtATime(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $pages = new PartyPageRead($statements);
        $pdo->beginTransaction();
        try {
            $token = 'pp' . bin2hex(random_bytes(4));
            for ($number = 1; $number <= 12; $number++) {
                SeedVendor::create($statements, $token . sprintf('%02d', $number), sprintf('A%02d', $number), $token);
            }
            $mine = ['name' => $token];

            $first = $pages->page($this->ask('', $mine, 'name', 'asc', 0), false);
            self::assertSame(12, $first->filtered);
            self::assertGreaterThanOrEqual(12, $first->total);
            self::assertSame(0, $first->start);
            self::assertCount(10, $first->rows);
            self::assertSame('A01 ' . $token, $first->rows[0]['name']);
            self::assertSame('person', $first->rows[0]['kind']);
            self::assertSame(['vendor'], $first->rows[0]['roles']);

            $second = $pages->page($this->ask('', $mine, 'name', 'asc', 10), false);
            self::assertSame(['A11 ' . $token, 'A12 ' . $token], array_column($second->rows, 'name'));

            $past = $pages->page($this->ask('', $mine, 'name', 'asc', 50), false);
            self::assertSame(10, $past->start);
            self::assertCount(2, $past->rows);

            $last = $pages->page($this->ask('', $mine, 'name', 'desc', 0), false);
            self::assertSame('A12 ' . $token, $last->rows[0]['name']);

            $all = $pages->page($this->ask('', $mine, 'kind', 'asc', 0, ItemPageWindow::ALL), false);
            self::assertCount(12, $all->rows);
            self::assertSame('A01 ' . $token, $all->rows[0]['name']);

            $byRole = $pages->page($this->ask('', $mine, 'roles', 'desc', 0), false);
            self::assertSame(12, $byRole->filtered);

            $searched = $pages->page($this->ask('a07 ' . $token, [], 'name', 'asc', 0), false);
            self::assertSame(['A07 ' . $token], array_column($searched->rows, 'name'));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function testRolesAndKindsMatchTheWordsStaffSee(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $pages = new PartyPageRead($statements);
        $pdo->beginTransaction();
        try {
            $token = 'pr' . bin2hex(random_bytes(4));
            SeedVendor::create($statements, $token, 'Rae', $token);

            $vendor = $pages->page($this->ask('', ['name' => $token, 'roles' => 'vend', 'kind' => 'pers'], 'name', 'asc', 0), false);
            self::assertSame(1, $vendor->filtered);

            $consignor = $pages->page($this->ask('', ['name' => $token, 'roles' => 'consignor'], 'name', 'asc', 0), true);
            self::assertSame(1, $consignor->filtered);

            $notHere = $pages->page($this->ask('', ['name' => $token, 'roles' => 'consignor'], 'name', 'asc', 0), false);
            self::assertSame(0, $notHere->filtered);
            self::assertSame([], $notHere->rows);
            self::assertSame(0, $notHere->start);

            $notCustomer = $pages->page($this->ask('', ['name' => $token, 'roles' => 'customer'], 'name', 'asc', 0), false);
            self::assertSame(0, $notCustomer->filtered);

            $organization = $pages->page($this->ask('', ['name' => $token, 'kind' => 'organization'], 'name', 'asc', 0), false);
            self::assertSame(0, $organization->filtered);

            $anyColumn = $pages->page($this->ask('consignor', ['name' => $token], 'name', 'asc', 0), true);
            self::assertSame(1, $anyColumn->filtered);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @param array<string, string> $columns
     */
    private function ask(
        string $search,
        array $columns,
        string $sort,
        string $direction,
        int $start,
        string $mode = ItemPageWindow::PAGE,
    ): PartyPageQuery {
        return new PartyPageQuery($search, $columns, $sort, $direction, new ItemPageWindow($start, 10, $mode, 1));
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
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
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
