<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PDO;
use PerfectConsign\Feature\Inventory\ItemCellContext;
use PerfectConsign\Feature\Inventory\ItemColumns;
use PerfectConsign\Feature\Inventory\ItemPageQuery;
use PerfectConsign\Feature\Inventory\ItemPageWindow;
use PerfectConsign\Feature\Inventory\ItemQueries;
use PerfectConsign\Feature\Inventory\ItemRowsPayload;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

final class ItemPageReadTest extends TestCase
{
    public function testATagOffTheFirstPageIsReturnedWhenThatColumnIsFiltered(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $queries = new ItemQueries($statements);
        $pdo->beginTransaction();
        try {
            $token = 'p' . bin2hex(random_bytes(4));
            $vendorA = SeedVendor::create($statements, $token . 'a', 'Ada', $token);
            $vendorB = SeedVendor::create($statements, $token . 'b', 'Bea', $token);
            $last = '';
            for ($number = 1; $number <= 11; $number++) {
                $last = $this->item($pdo, $statements, $vendorA, $token . sprintf('%02d', $number), $number * 100, $token);
            }
            $this->item($pdo, $statements, $vendorB, $token . 'zz', 50, $token);
            $today = '2026-06-15';
            $needle = 'floor ' . $token;
            $keys = ItemColumns::keys(ItemColumns::staff('show', [], 'Vendor'));

            $first = $queries->page($this->page($needle, $vendorA, $today, $keys, 0, 10));
            self::assertSame(11, $first->total);
            self::assertSame(11, $first->filtered);
            self::assertNotContains($token . '11', array_column($first->rows, 'code'));

            $found = $queries->page($this->page($needle, $vendorA, $today, $keys, 0, 10, $token . '11'));
            self::assertSame([$token . '11'], array_column($found->rows, 'code'));
            self::assertSame(1, $found->filtered);
            self::assertSame(11, $found->total);

            $priced = $queries->page($this->page($needle, $vendorA, $today, $keys, 0, 10, '', 'price', 'desc'));
            self::assertSame($token . '11', $priced->rows[0]['code'] ?? null);

            $scoped = $queries->page($this->page($needle, $vendorA, $today, $keys, 0, 100));
            self::assertNotContains($token . 'zz', array_column($scoped->rows, 'code'));

            self::assertSame(10, $queries->place($needle, '', $vendorA, $today, $last));

            $exported = $queries->page($this->page($needle, $vendorA, $today, $keys, 0, 10, '', 'code', 'asc', ItemPageWindow::ALL));
            self::assertCount(11, $exported->rows);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function testTheInventoryShellDoesNotPrintTheFloor(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Inventory/inventory', [
            'slug' => 'demo',
            'csrf' => 'token',
            'query' => '',
            'stock' => '',
            'available' => 11,
            'unsold' => 0,
            'sold' => 0,
            'unprinted' => 0,
            'total' => 11,
            'confirm' => '01900000-0000-7000-8000-0000000000aa',
            'confirmIndex' => 20,
            'attributeLists' => [],
            'labelStock' => 'avery_5160',
            'stocks' => [],
            'showBrand' => true,
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('data-dt-server="1"', $html);
        self::assertStringContainsString('data-dt-start="20"', $html);
        self::assertStringContainsString('/demo/inventory/rows?', $html);
        self::assertStringContainsString('<tbody></tbody>', $html);
        self::assertStringNotContainsString('id="item-', $html);
        self::assertDoesNotMatchRegularExpression('/<tbody>\s*<tr/', $html);
    }

    public function testRowMarkupKeepsTheDeletePromptPriceAndVendorAge(): void
    {
        $staff = $this->staffRow();
        self::assertStringContainsString('form="stickers"', $staff);
        self::assertStringContainsString('Delete this item?', $staff);
        self::assertStringContainsString('$12.50', $staff);
        self::assertStringContainsString('Overridden', $staff);
        self::assertStringContainsString('12-01-2026', $staff);
        self::assertStringContainsString('class="muted">—', $staff);
        self::assertStringContainsString('On floor', $staff);

        $vendor = $this->vendorRow();
        self::assertStringContainsString('vendor-item-row--age-0', $vendor);
        self::assertStringContainsString('class="num vendor-days">12</span>', $vendor);
        self::assertStringContainsString('Wool', $vendor);
        self::assertStringContainsString('as=01900000-0000-7000-8000-0000000000aa', $vendor);
    }

    private function staffRow(): string
    {
        $item = [
            'id' => '01900000-0000-7000-8000-0000000000i1',
            'code' => 'T1',
            'barcode' => 'BC1',
            'description' => 'Coat',
            'price_minor' => 1250,
            'quantity' => 4,
            'vendor_id' => '',
            'vendor_label' => '',
            'brand_name' => '',
            'intake_on' => '2026-09-01',
            'expires_on' => '2026-12-01',
            'take_label' => '40',
            'overridden' => true,
            'deletable' => true,
            'status_label' => 'On floor',
        ];
        $packed = (new ItemRowsPayload())->pack(
            [$item],
            ItemColumns::staff('show', [], 'Vendor'),
            new ItemCellContext('demo', 'token', '', '', $item['id'], 'vendors', ''),
            'staff',
        );

        return $this->flatten($packed[0] ?? []);
    }

    private function vendorRow(): string
    {
        $item = [
            'id' => '01900000-0000-7000-8000-0000000000i2',
            'code' => 't2',
            'barcode' => 'b2',
            'description' => 'Wool coat',
            'price_minor' => 1000,
            'quantity' => 1,
            'brand_name' => 'Wool',
            'intake_on' => '2026-09-01',
            'expires_on' => '',
            'take_label' => '0',
            'overridden' => false,
            'days_on_floor' => 12,
            'age_tier' => 0,
        ];
        $packed = (new ItemRowsPayload())->pack(
            [$item],
            ItemColumns::vendor('show', []),
            new ItemCellContext('demo', 'token', '', '', '', 'vendors', '01900000-0000-7000-8000-0000000000aa'),
            'vendor',
        );
        $row = $packed[0] ?? [];

        return (is_string($row['DT_RowClass'] ?? null) ? $row['DT_RowClass'] : '') . $this->flatten($row);
    }

    /**
     * @param array<mixed> $row
     */
    private function flatten(array $row): string
    {
        $html = '';
        foreach ($row as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $html .= $value;
            }
        }

        return $html;
    }

    /**
     * @param list<string> $keys
     */
    private function page(
        string $token,
        string $vendorId,
        string $today,
        array $keys,
        int $start,
        int $length,
        string $code = '',
        string $sort = 'code',
        string $direction = 'asc',
        string $mode = ItemPageWindow::PAGE,
    ): ItemPageQuery {
        $columns = [];
        if ($code !== '') {
            $columns['code'] = strtolower($code);
        }

        return new ItemPageQuery(
            new \PerfectConsign\Feature\Inventory\ItemPageFilter($token, '', $vendorId, $columns, '', $keys),
            $sort,
            $direction,
            new ItemPageWindow($start, $length, $mode, 1),
            $today,
        );
    }

    private function item(PDO $pdo, Statements $statements, string $vendorId, string $code, int $price, string $token): string
    {
        $statements->change(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
             VALUES (?::uuid, ?, ?::uuid, ?, ?, ?, ?::date, 1)',
            [$this->id($pdo), $code, $vendorId, 'Floor ' . $token, $price, 'BC-' . $code, '2026-06-01'],
        );
        $rows = $statements->bound('SELECT id::text AS id FROM items WHERE code = ?', [$code]);
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            self::fail('the item was not inserted');
        }

        return $id;
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
