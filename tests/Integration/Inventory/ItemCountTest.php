<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Inventory;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\RolePaths;
use PerfectConsign\Feature\Count\CountReasons;
use PerfectConsign\Feature\Dev\DemoSubject;
use PerfectConsign\Feature\Inventory\CountQuestion;
use PerfectConsign\Feature\Inventory\CountWho;
use PerfectConsign\Feature\Inventory\DepartedPiece;
use PerfectConsign\Feature\Inventory\ItemCount;
use PerfectConsign\Feature\Inventory\ItemCountDesk;
use PerfectConsign\Feature\Inventory\ItemRecount;
use PerfectConsign\Feature\Party\CloseOut;
use PerfectConsign\Feature\Party\VendorMoveOut;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

/**
 * Item counts and move-out against the real database (D10, D11): the reasons,
 * a count changed by hand with who and why, the history, the Close out vendor
 * page's work, a piece held for a vendor who left, and the waiting days before
 * the store may keep what is left. One transaction, rolled back.
 */
final class ItemCountTest extends TestCase
{
    public function testCountsChangeOnlyWithAReasonAndAVendorClosesOut(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $session = new Session(null, false);
        DemoSubject::stamp($pdo, $schema, $session);
        $actor = new Actor($session);
        $statements = new Statements($pdo);
        $audit = QuietAudit::journal($statements, $actor, $session);
        $counts = new ItemCount($statements);
        $closeOut = new CloseOut($statements, $counts, $audit);
        $moveOut = new VendorMoveOut($statements);
        $pieces = new DepartedPiece($statements, $audit);
        $reasons = new CountReasons($statements, $audit);
        $pdo->beginTransaction();
        try {
            $today = $this->today($statements);
            $staff = CountWho::of($actor);
            self::assertSame('staff', $staff->kind);
            self::assertNull($staff->vendorId);
            self::assertNotSame('', $staff->name);

            $tag = bin2hex(random_bytes(3));
            $mary = $this->vendor($statements, 'm' . $tag, 'Mary', 'Smith' . $tag);
            $vase = $this->item($statements, $mary, 'M' . $tag, 3);
            $lamp = $this->item($statements, $mary, 'L' . $tag, 1);

            $offered = array_column($counts->reasons($staff), 'code');
            foreach (['took_home', 'lost', 'damaged', 'entered_wrong'] as $code) {
                self::assertContains($code, $offered);
            }
            $tookHome = $this->reasonId($counts->reasons($staff), 'took_home');
            $damaged = $this->reasonId($counts->reasons($staff), 'damaged');

            // The question comes before a lower count, never for the same or a higher one.
            $stored = ['quantity' => 3];
            self::assertTrue(CountQuestion::needed($stored, '2', ''));
            self::assertFalse(CountQuestion::needed($stored, '2', $tookHome));
            self::assertFalse(CountQuestion::needed($stored, '3', ''));
            self::assertFalse(CountQuestion::needed($stored, '4', ''));
            self::assertFalse(CountQuestion::needed(null, '0', ''));
            self::assertFalse(CountQuestion::needed($stored, 'two', ''));
            self::assertSame('3', CountQuestion::was($stored));
            self::assertSame('', CountQuestion::was(null));

            $desk = new ItemCountDesk($counts, $actor, $pieces);
            self::assertTrue($desk->asks($stored, '1', ''));
            $view = $desk->view($vase, $stored, true);
            self::assertSame('3', $view['countWas']);
            self::assertNotSame([], $view['countReasons']);
            self::assertNull($view['departed']);
            self::assertSame([], $view['countHistory']);

            $this->refused('Choose why there are fewer.', fn () => $counts->change($vase, 2, '', $staff), $pdo);
            $desk->recount($damaged)->apply($vase, 2);
            (new ItemRecount($counts, '', $staff))->apply($vase, 5);
            self::assertSame(5, $this->onHand($statements, $vase));
            $history = $counts->history($vase);
            self::assertCount(2, $history);
            self::assertStringStartsWith($staff->name . ': 2 → 5, Added more, ', $history[0]);
            self::assertStringStartsWith($staff->name . ': 3 → 2, Damaged, ', $history[1]);
            self::assertSame($history, $desk->view($vase, null, false)['countHistory']);
            self::assertSame('', $desk->view($vase, null, false)['countWas']);
            $byVendor = $moveOut->history($mary);
            self::assertSame('M' . $tag, $byVendor[0]['code']);
            self::assertSame($history[0], $byVendor[0]['said']);
            self::assertSame($byVendor, $counts->vendorHistory($mary));

            // A vendor in the portal changes only their own items.
            $session->set('actor_role', 'vendor');
            $session->set('actor_vendor', $mary);
            $session->set('owner_name', 'Mary Smith');
            $asVendor = CountWho::of($actor);
            self::assertSame('vendor', $asVendor->kind);
            self::assertSame($mary, $asVendor->vendorId);
            $counts->change($vase, 4, $this->reasonId($counts->reasons($asVendor), 'lost'), $asVendor);
            $joe = $this->vendor($statements, 'j' . $tag, 'Joe', 'Brown' . $tag);
            $joeItem = $this->item($statements, $joe, 'J' . $tag, 1);
            $this->refused('That item is not yours.', fn () => $counts->change($joeItem, 0, $tookHome, $asVendor), $pdo);
            $session->set('actor_vendor', '');
            $this->refused('the signed-in person is missing', fn () => CountWho::of($actor), $pdo);
            DemoSubject::stamp($pdo, $schema, $session);

            // Leaving soon: a booth that ends within 14 days, with what is still counted.
            $ann = $this->vendor($statements, 'a' . $tag, 'Ann', 'Soon' . $tag);
            $this->item($statements, $ann, 'A' . $tag, 2);
            $this->stay($statements, $this->booth($statements, 'b' . $tag), $ann, $this->shift($statements, $today, 10));
            $leaving = $moveOut->leaving($ann, $today);
            self::assertNotNull($leaving);
            self::assertSame(2, $leaving['items']);
            self::assertSame($this->shift($statements, $today, 10), $leaving['ends_on']);
            self::assertContains($ann, array_column($moveOut->soon($today), 'vendor_id'));
            self::assertNull($moveOut->leaving($mary, $today));

            // Close out: each part shows; counting out clears Items; then Make inactive.
            self::assertSame(['id' => $mary, 'name' => 'Mary Smith' . $tag, 'active' => true], $closeOut->vendor($mary));
            self::assertNull($closeOut->vendor('01900000-0000-7000-8000-00000000ffff'));
            self::assertCount(2, $closeOut->items($mary));
            $open = $closeOut->open($mary);
            self::assertSame('They still have 5 items in the store. Close them out first.', $open['items']);
            self::assertArrayNotHasKey('booth', $open);
            self::assertIsBool($closeOut->depositsOn());
            self::assertSame('', $closeOut->keepFrom($mary));
            $this->refused('That item is not theirs.', fn () => $closeOut->countOut($mary, $joeItem, $tookHome, $staff), $pdo);
            $closeOut->countOut($mary, $lamp, $damaged, $staff);
            self::assertSame(0, $this->onHand($statements, $lamp));
            self::assertSame(1, $closeOut->tookEverything($mary, $staff));
            self::assertSame([], $closeOut->items($mary));
            self::assertSame(0, $closeOut->tookEverything($mary, $staff));

            // Waiting days: off until the owner sets them, and only after the booth ends.
            self::assertSame('', $reasons->waitDays());
            $tenant = RowValue::string($statements->rows('SELECT id::text AS id FROM platform.tenants WHERE schema_name = current_schema()')[0]['id'] ?? null);
            $this->refusedInput('Enter the days as a whole number from 1 to 365, or leave it empty.', fn () => $reasons->saveWaitDays('0', $tenant), $pdo);
            $this->refusedInput('Enter the days as a whole number from 1 to 365, or leave it empty.', fn () => $reasons->saveWaitDays('366', $tenant), $pdo);
            $reasons->saveWaitDays('30', $tenant);
            self::assertSame('30', $reasons->waitDays());
            $statements->change('UPDATE mall SET store_stock_enabled = true', []);
            self::assertSame('', $closeOut->keepFrom($mary));
            self::assertSame($this->shift($statements, $today, 40), $closeOut->keepFrom($ann));
            $lee = $this->vendor($statements, 'l' . $tag, 'Lee', 'Left' . $tag);
            $this->stay($statements, $this->booth($statements, 'c' . $tag), $lee, $this->shift($statements, $today, -31));
            self::assertSame($this->shift($statements, $today, -1), $closeOut->keepFrom($lee));
            $kept = $this->item($statements, $lee, 'K' . $tag, 1);
            $this->refused('That item is not theirs.', fn () => $closeOut->keep($mary, $kept, $today), $pdo);
            $closeOut->keep($lee, $kept, $today);
            $placed = $statements->bound('SELECT placement, vendor_id FROM items WHERE id = ?::uuid', [$kept]);
            self::assertSame('store', RowValue::string($placed[0]['placement'] ?? null));
            self::assertNull($placed[0]['vendor_id'] ?? null);
            $reasons->saveWaitDays('', $tenant);
            self::assertSame('', $reasons->waitDays());

            $statements->change('UPDATE vendors SET active = false WHERE id = ?::uuid', [$mary]);
            self::assertSame([], $closeOut->open($mary));

            // A stray piece: held for Mary, then picked up.
            self::assertNull($pieces->of($joeItem));
            $stray = $this->strayFor($statements, $mary, 'S' . $tag);
            $departed = $pieces->of($stray);
            self::assertNotNull($departed);
            self::assertSame('', $departed['held_on']);
            $pieces->hold($stray, $today, $staff->name);
            self::assertSame($today, $pieces->of($stray)['held_on'] ?? null);
            self::assertCount(1, $closeOut->held($mary));
            $this->refused('That piece is not held for them.', fn () => $closeOut->pickedUp($mary, $vase, $today, $staff->name), $pdo);
            $closeOut->pickedUp($mary, $stray, $today, $staff->name);
            self::assertSame([], $closeOut->held($mary));

            // The store's reasons.
            $label = 'Sent to repair ' . $tag;
            $reasons->add($label, 'leaving', 'staff');
            $listed = $this->listedReason($reasons->all(), $label);
            self::assertSame('staff', $listed['who']);
            self::assertTrue($listed['active']);
            self::assertFalse($listed['used']);
            $this->refusedInput('That reason is already on the list.', fn () => $reasons->add(strtoupper($label), 'leaving', 'staff'), $pdo);
            $this->refusedInput('Choose who may use this reason.', fn () => $reasons->add('x' . $tag, 'leaving', 'nobody'), $pdo);
            $this->refusedInput('Choose whether the piece left the store or it was a correction.', fn () => $reasons->add('y' . $tag, 'sideways', 'both'), $pdo);
            $this->refusedInput('Enter the reason in 60 letters or fewer.', fn () => $reasons->add(' ', 'leaving', 'both'), $pdo);
            $reasons->save($listed['id'], $label . ' shop', 'correction', 'both');
            self::assertSame('correction', $this->listedReason($reasons->all(), $label . ' shop')['kind']);
            $reasons->setActive($listed['id'], false);
            self::assertFalse($this->listedReason($reasons->all(), $label . ' shop')['active']);
            $reasons->remove($listed['id']);
            $this->refusedInput('That reason was not found.', fn () => $reasons->remove($listed['id']), $pdo);
            $this->refused('That reason is in the count history. Switch it off instead.', fn () => $reasons->remove($damaged), $pdo);
            self::assertTrue($this->listedReason($reasons->all(), 'Damaged')['used']);

            self::assertTrue(RolePaths::keepLeftBehind('/vendors/' . $mary . '/close-out/keep'));
            self::assertFalse(RolePaths::keepLeftBehind('/vendors/' . $mary . '/close-out/count'));
        } finally {
            $pdo->rollBack();
        }
    }

    private function vendor(Statements $statements, string $code, string $first, string $last): string
    {
        $rows = $statements->bound('SELECT uuidv7()::text AS id', []);
        $id = RowValue::string($rows[0]['id'] ?? null);
        SeedVendor::withId($statements, $id, $code, $first, $last);

        return $id;
    }

    private function item(Statements $statements, string $vendorId, string $code, int $count): string
    {
        $rows = $statements->bound(
            'INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, quantity)
             VALUES (?, ?::uuid, ?, 1000, ?, CURRENT_DATE, ?)
             RETURNING id::text AS id',
            [$code, $vendorId, 'Piece ' . $code, 'BC-' . $code, $count]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * A piece of Mary's that turns up after she left: entered while she was
     * Active, counted out, and found again once she is gone.
     */
    private function strayFor(Statements $statements, string $vendorId, string $code): string
    {
        $statements->change('UPDATE vendors SET active = true WHERE id = ?::uuid', [$vendorId]);
        $id = $this->item($statements, $vendorId, $code, 1);
        $statements->bound(
            "SELECT stall_change_item_quantity(?::uuid, 0, reason.id, 'staff', 'Ada', NULL) FROM quantity_reasons AS reason WHERE reason.code = 'lost'",
            [$id]
        );
        $statements->change('UPDATE vendors SET active = false WHERE id = ?::uuid', [$vendorId]);

        return $id;
    }

    private function stay(Statements $statements, string $boothId, string $vendorId, string $endsOn): void
    {
        $statements->change(
            'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, ?::date, ?::date)',
            [$boothId, $vendorId, '2026-01-01', $endsOn]
        );
    }

    private function booth(Statements $statements, string $code): string
    {
        $rows = $statements->bound('INSERT INTO booths (code, name) VALUES (?, ?) RETURNING id::text AS id', [$code, 'Booth ' . $code]);

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private function onHand(Statements $statements, string $itemId): int
    {
        $rows = $statements->bound('SELECT quantity FROM items WHERE id = ?::uuid', [$itemId]);

        return RowValue::integer($rows[0]['quantity'] ?? null);
    }

    private function today(Statements $statements): string
    {
        $rows = $statements->rows("SELECT to_char((clock_timestamp() AT TIME ZONE time_zone)::date, 'YYYY-MM-DD') AS today FROM mall");

        return RowValue::string($rows[0]['today'] ?? null);
    }

    private function shift(Statements $statements, string $day, int $days): string
    {
        $rows = $statements->bound("SELECT to_char(?::date + ?::integer, 'YYYY-MM-DD') AS day", [$day, $days]);

        return RowValue::string($rows[0]['day'] ?? null);
    }

    /**
     * @param list<array{id: string, code: string, label: string}> $offered
     */
    private function reasonId(array $offered, string $code): string
    {
        foreach ($offered as $reason) {
            if ($reason['code'] === $code) {
                return $reason['id'];
            }
        }
        self::fail('no ' . $code . ' reason is offered');
    }

    /**
     * @param list<array{id: string, label: string, kind: string, who: string, active: bool, used: bool}> $all
     * @return array{id: string, label: string, kind: string, who: string, active: bool, used: bool}
     */
    private function listedReason(array $all, string $label): array
    {
        foreach ($all as $reason) {
            if ($reason['label'] === $label) {
                return $reason;
            }
        }
        self::fail('the reason ' . $label . ' is not listed');
    }

    private function refused(string $message, callable $attempt, PDO $pdo): void
    {
        $pdo->exec('SAVEPOINT refused');
        try {
            $attempt();
            self::fail('accepted: ' . $message);
        } catch (DatabaseRefusal $refusal) {
            self::assertSame($message, $refusal->getMessage());
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT refused');
    }

    private function refusedInput(string $message, callable $attempt, PDO $pdo): void
    {
        $pdo->exec('SAVEPOINT refused');
        try {
            $attempt();
            self::fail('accepted: ' . $message);
        } catch (\InvalidArgumentException | DatabaseRefusal $refusal) {
            self::assertSame($message, $refusal->getMessage());
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT refused');
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        $schema = $listed === false ? null : $listed->fetchColumn();
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
