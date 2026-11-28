<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * Item counts and move-out through the real screens (D10, D11): a lower count
 * asks why, the history shows who and why, Settings → Item counts keeps the
 * reasons, the Close out vendor page counts everything out and turns on Make
 * inactive, a stray piece is refused at the register and held for the vendor,
 * and the store keeps a piece left behind only after the waiting days.
 */
final class CloseOutTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = self::client();
        self::assertSees("Today's sales", $this->owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD));
    }

    public function testALowerCountAsksWhyAndTheHistorySaysWhoAndWhy(): void
    {
        $vendor = $this->vendor('Rosa');
        $item = $this->item($vendor['id'], 3);
        $fields = $this->itemFields($vendor['id'], $item, '1');

        $asked = $this->owner->post('/' . self::SLUG . '/inventory/' . $item['id'], $fields);
        self::assertSees('Why are there fewer?', $asked);
        self::assertSees('The count goes from 3 to 1.', $asked);
        self::assertSame(3, $this->onHand($item['id']));

        $saved = $this->owner->post('/' . self::SLUG . '/inventory/' . $item['id'], $fields + [
            'quantity_reason' => self::countReason($asked->body, 'Lost or stolen'),
        ]);
        self::assertSees('Item saved.', $saved);
        self::assertSees('Count history', $saved);
        self::assertSees(ProbeMall::USERNAME . ': 3 → 1, Lost or stolen', $saved);
        self::assertSame(1, $this->onHand($item['id']));

        $more = $this->owner->post('/' . self::SLUG . '/inventory/' . $item['id'], $this->itemFields($vendor['id'], $item, '4'));
        self::assertSees('Item saved.', $more);
        self::assertSees(ProbeMall::USERNAME . ': 1 → 4, Added more', $more);

        $page = $this->owner->get('/' . self::SLUG . '/vendors/' . $vendor['id']);
        self::assertSees('Item count history', $page);
        self::assertSees('Close out', $page);
    }

    public function testSettingsKeepTheReasonsAndTheWaitingDays(): void
    {
        $page = $this->owner->get('/' . self::SLUG . '/settings/counts');
        self::assertSees('Why are there fewer?', $page);
        self::assertSees('Took it home', $page);
        self::assertSees('Items left behind', $page);

        $label = 'Sent to repair ' . bin2hex(random_bytes(2));
        $added = $this->owner->post('/' . self::SLUG . '/settings/counts/reasons', ['label' => $label, 'kind' => 'leaving', 'who' => 'staff']);
        self::assertSees('Reason added.', $added);
        self::assertSees($label, $added);
        $again = $this->owner->post('/' . self::SLUG . '/settings/counts/reasons', ['label' => $label, 'kind' => 'leaving', 'who' => 'staff']);
        self::assertSees('That reason is already on the list.', $again);
        $id = $this->reasonId($label);

        $renamed = $label . ' shop';
        $saved = $this->owner->post('/' . self::SLUG . '/settings/counts/reasons/' . $id, ['label' => $renamed, 'kind' => 'correction', 'who' => 'both']);
        self::assertSees('Reason saved.', $saved);
        self::assertSees($renamed, $saved);
        $off = $this->owner->post('/' . self::SLUG . '/settings/counts/reasons/' . $id . '/active', ['active' => '0']);
        self::assertSees('Reason switched off.', $off);
        $on = $this->owner->post('/' . self::SLUG . '/settings/counts/reasons/' . $id . '/active', ['active' => '1']);
        self::assertSees('Reason switched on.', $on);
        $gone = $this->owner->post('/' . self::SLUG . '/settings/counts/reasons/' . $id . '/delete', []);
        self::assertSees('Reason deleted.', $gone);
        self::assertStringNotContainsString($renamed, $gone->body);

        $bad = $this->owner->post('/' . self::SLUG . '/settings/counts/wait', ['abandon_wait_days' => '400']);
        self::assertSees('Enter the days as a whole number from 1 to 365, or leave it empty.', $bad);
        $wait = $this->owner->post('/' . self::SLUG . '/settings/counts/wait', ['abandon_wait_days' => '']);
        self::assertSees('Saved.', $wait);
    }

    public function testTheCloseOutPageCountsEverythingOutAndAStrayIsHeld(): void
    {
        $vendor = $this->vendor('Nell');
        $vase = $this->item($vendor['id'], 2);
        $lamp = $this->item($vendor['id'], 1);
        $base = '/' . self::SLUG . '/vendors/' . $vendor['id'] . '/close-out';

        $page = $this->owner->get($base);
        self::assertSees('Close out ' . $vendor['name'], $page);
        self::assertSees('They still have 3 items in the store. Close them out first.', $page);
        self::assertSees('Mark all as Took it home', $page);
        self::assertSees('This turns on when every part above has a check mark.', $page);

        $counted = $this->owner->post($base . '/count', [
            'item_id' => $lamp['id'],
            'quantity_reason' => $this->rowReason($page->body, $lamp['id'], 'Damaged'),
        ]);
        self::assertSees('Item counted out.', $counted);
        self::assertSame(0, $this->onHand($lamp['id']));
        $everything = $this->owner->post($base . '/all', []);
        self::assertSees('Every item is marked Took it home.', $everything);
        self::assertSees('No items are left. Nothing to count out.', $everything);
        self::assertSame(0, $this->onHand($vase['id']));

        $listed = $this->owner->get('/' . self::SLUG . '/inventory/vendors.json?q=' . rawurlencode($vendor['code']));
        self::assertStringContainsString($vendor['id'], $listed->body);
        $inactive = $this->owner->post('/' . self::SLUG . '/vendors/' . $vendor['id'] . '/standing', ['standing' => 'inactive']);
        self::assertSees('Vendor is inactive.', $inactive);
        // Choosing a seller lists only Active vendors, by name or by code.
        $gone = $this->owner->get('/' . self::SLUG . '/inventory/vendors.json?q=' . rawurlencode($vendor['code']));
        self::assertStringNotContainsString($vendor['id'], $gone->body);
        $byCode = $this->owner->get('/' . self::SLUG . '/inventory/vendors.json?code=' . rawurlencode($vendor['code']));
        self::assertSame('[]', trim($byCode->body));

        // The stray: the register refuses it and says what to do; staff hold it.
        self::useOpenRegister($this->owner, self::SLUG);
        $scan = $this->owner->post('/' . self::SLUG . '/pos/scan', ['scan' => $vase['code']]);
        self::assertSees('This piece belongs to ' . $vendor['name'] . ', who has left the store. Please set it aside and give it to the manager.', $scan);
        $instant = $this->owner->post('/' . self::SLUG . '/pos/instant', [
            'vendor_id' => $vendor['id'],
            'description' => 'Late lamp',
            'price' => '5.00',
            'quantity' => '1',
        ]);
        self::assertSees($vendor['name'] . ' has left the store. Choose another vendor.', $instant);
        $edit = $this->owner->get('/' . self::SLUG . '/inventory/' . $vase['id'] . '/edit');
        self::assertSees('Hold it for ' . $vendor['name'], $edit);
        $held = $this->owner->post('/' . self::SLUG . '/inventory/' . $vase['id'] . '/hold', []);
        self::assertSees('Held for ' . $vendor['name'] . '.', $held);
        $again = $this->owner->post('/' . self::SLUG . '/inventory/' . $vase['id'] . '/hold', []);
        self::assertSees('That piece is already held.', $again);
        $notLeft = $this->item($this->vendor('Opal')['id'], 1);
        $refused = $this->owner->post('/' . self::SLUG . '/inventory/' . $notLeft['id'] . '/hold', []);
        self::assertSees('Only a piece from a vendor who has left is held for them.', $refused);

        $close = $this->owner->get($base);
        self::assertSees('Held for ' . explode(' ', $vendor['name'])[0], $close);
        self::assertSees($vendor['name'] . ' is inactive.', $close);
        $picked = $this->owner->post($base . '/picked-up', ['item_id' => $vase['id']]);
        self::assertSees('Pickup saved.', $picked);
        $twice = $this->owner->post($base . '/picked-up', ['item_id' => $vase['id']]);
        self::assertSees('That piece is not held for them.', $twice);

        $newItem = $this->owner->post('/' . self::SLUG . '/inventory', $this->itemFields($vendor['id'], ['code' => 'n' . bin2hex(random_bytes(3)), 'description' => 'Late piece'], '1'));
        self::assertSees($vendor['name'] . ' has left the store. Choose another vendor.', $newItem);
    }

    public function testTheStoreKeepsWhatIsLeftOnlyAfterTheWaitingDays(): void
    {
        $vendor = $this->vendor('Ivy');
        $quilt = $this->item($vendor['id'], 1);
        $booth = $this->booth();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')));
        ProbeMall::plantStay($booth, $vendor['id'], '2026-01-01', $today->modify('-10 days')->format('Y-m-d'));
        $base = '/' . self::SLUG . '/vendors/' . $vendor['id'] . '/close-out';
        // The kept piece becomes store stock, so store stock is on from here,
        // the same as after a buy at the expire desk.
        $this->execute('UPDATE %s.mall SET store_stock_enabled = true WHERE NOT store_stock_enabled');
        try {
            $off = $this->owner->post($base . '/keep', ['item_id' => $quilt['id']]);
            self::assertSees('Keeping left-behind items is off.', $off);
            self::assertSees('Saved.', $this->owner->post('/' . self::SLUG . '/settings/counts/wait', ['abandon_wait_days' => '30']));
            $early = $this->owner->get($base);
            self::assertSees('The store can keep what is left from', $early);
            $tooSoon = $this->owner->post($base . '/keep', ['item_id' => $quilt['id']]);
            self::assertSees('The store can keep ' . $vendor['name'] . '\'s items from', $tooSoon);
            self::assertSees('Saved.', $this->owner->post('/' . self::SLUG . '/settings/counts/wait', ['abandon_wait_days' => '5']));
            self::assertSees('The store keeps it', $this->owner->get($base));
            $kept = $this->owner->post($base . '/keep', ['item_id' => $quilt['id']]);
            self::assertSees('The store keeps that piece. It is now store stock.', $kept);
            self::assertSame('store', $this->scalar('SELECT placement FROM %s.items WHERE id = \'' . $quilt['id'] . '\''));
        } finally {
            $this->owner->post('/' . self::SLUG . '/settings/counts/wait', ['abandon_wait_days' => '']);
        }
    }

    /**
     * @return array{id: string, name: string, code: string}
     */
    private function vendor(string $first): array
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $this->owner->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => $first,
            'last_name' => $code,
            'store_take' => '40',
        ]);
        self::assertSees('Vendor created.', $created);
        if (preg_match('#/vendors/([0-9a-fA-F-]{36})/sign-in#', $created->body, $found) !== 1) {
            self::fail('the new vendor has no id on the page');
        }

        return ['id' => strtolower($found[1]), 'name' => $first . ' ' . $code, 'code' => $code];
    }

    /**
     * @return array{id: string, code: string, description: string}
     */
    private function item(string $vendorId, int $count): array
    {
        $item = ['code' => 't' . bin2hex(random_bytes(3)), 'description' => 'Piece ' . bin2hex(random_bytes(2))];
        $created = $this->owner->post('/' . self::SLUG . '/inventory', $this->itemFields($vendorId, $item, (string) $count));
        self::assertSees('Item created.', $created);
        if (preg_match('/name="item" value="([0-9a-fA-F-]{36})"/', $created->body, $found) !== 1) {
            self::fail('the new item has no id on the page');
        }

        return ['id' => strtolower($found[1])] + $item;
    }

    /**
     * @param array{code: string, description: string} $item
     * @return array<string, string>
     */
    private function itemFields(string $vendorId, array $item, string $count): array
    {
        return [
            'code' => $item['code'],
            'description' => $item['description'],
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '12.00',
            'quantity' => $count,
            'barcode' => 'BC-' . $item['code'],
            'intake_on' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d'),
            'placement' => 'consigned',
        ];
    }

    /**
     * The value of a reason button in one item's row on the Close out page.
     */
    private function rowReason(string $page, string $itemId, string $label): string
    {
        $pattern = '#name="item_id" value="' . preg_quote($itemId, '#') . '">(?:(?!</form>).)*?name="quantity_reason" value="([0-9a-f-]{36})">'
            . preg_quote($label, '#') . '<#s';
        if (preg_match($pattern, $page, $found) !== 1) {
            self::fail('the Close out page has no ' . $label . ' button for that item');
        }

        return $found[1];
    }

    private function booth(): string
    {
        $code = 'b' . bin2hex(random_bytes(3));
        self::assertSees('Booth created.', $this->owner->post('/' . self::SLUG . '/booths', ['code' => $code, 'name' => $code, 'amount' => '40.00']));

        return $this->scalar('SELECT id::text FROM %s.booths WHERE code = \'' . $code . '\'');
    }

    private function reasonId(string $label): string
    {
        $statement = $this->pdo()->prepare(sprintf('SELECT id::text FROM %s.quantity_reasons WHERE label = ?', $this->schema()));
        $statement->execute([$label]);

        return (string) $statement->fetchColumn();
    }

    private function onHand(string $itemId): int
    {
        return (int) $this->scalar('SELECT quantity::text FROM %s.items WHERE id = \'' . $itemId . '\'');
    }

    private function scalar(string $sql): string
    {
        $statement = $this->pdo()->query(sprintf($sql, $this->schema()));
        $value = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($value)) {
            self::fail('no value for ' . $sql);
        }

        return $value;
    }

    private function execute(string $sql): void
    {
        $this->pdo()->exec(sprintf($sql, $this->schema()));
    }

    private function schema(): string
    {
        $statement = $this->pdo()->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([self::SLUG]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('probe schema missing');
        }

        return $schema;
    }

    private function pdo(): PDO
    {
        $host = getenv('STALL_DB_HOST') ?: '127.0.0.1';
        $port = getenv('STALL_DB_PORT') ?: '5436';
        $name = getenv('STALL_DB_NAME') ?: 'consign';
        $user = getenv('STALL_DB_USER') ?: 'stall';
        $password = getenv('STALL_DB_PASSWORD') ?: 'stall';
        $pdo = new PDO('pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
