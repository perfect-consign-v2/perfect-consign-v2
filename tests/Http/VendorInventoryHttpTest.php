<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Feature\Mall\MallMeasure;
use PerfectConsign\Feature\Vendor\VendorEntry;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ItemListBody;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PDO;

final class VendorInventoryHttpTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = self::client();
        $signedIn = $this->owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    protected function tearDown(): void
    {
        $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(true, false));
        parent::tearDown();
    }

    public function testBuildingSaveKeepsInventoryOffUnlessThePortalIsOn(): void
    {
        $both = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(true, true));
        self::assertSees('Building saved.', $both);
        self::assertMatchesRegularExpression('/id="vendor_portal_enabled"[^>]*checked/', $both->body);
        self::assertMatchesRegularExpression('/id="vendor_inventory_enabled"[^>]*checked/', $both->body);

        $forced = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(false, true));
        self::assertSees('Building saved.', $forced);
        self::assertDoesNotMatchRegularExpression('/id="vendor_portal_enabled"[^>]*checked/', $forced->body);
        self::assertDoesNotMatchRegularExpression('/id="vendor_inventory_enabled"[^>]*checked/', $forced->body);

        $inventory = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(true, true));
        self::assertMatchesRegularExpression('/id="vendor_inventory_enabled"[^>]*checked/', $inventory->body);
        $closed = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(false, false));
        self::assertDoesNotMatchRegularExpression('/id="vendor_inventory_enabled"[^>]*checked/', $closed->body);
    }

    public function testWeeklyVendorLetterStartsOffAndAMoneySaveLeavesItOn(): void
    {
        $open = $this->owner->get('/' . self::SLUG . '/settings/building');
        self::assertSees('Weekly vendor letter', $open);
        self::assertSees('It starts off.', $open);
        self::assertDoesNotMatchRegularExpression('/id="vendor_weekly_mail_enabled"[^>]*checked/', $open->body);
        self::assertFalse($this->weeklyMailOn());

        $bad = $this->building(true, false, '', true);
        $bad['booth_width_ft'] = '0';
        $refused = $this->owner->post('/' . self::SLUG . '/settings/building', $bad);
        self::assertSees('booth size is whole feet', $refused);
        self::assertMatchesRegularExpression('/id="vendor_weekly_mail_enabled"[^>]*checked/', $refused->body);
        self::assertFalse($this->weeklyMailOn());

        $saved = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(true, false, '', true));
        self::assertSees('Building saved.', $saved);
        self::assertMatchesRegularExpression('/id="vendor_weekly_mail_enabled"[^>]*checked/', $saved->body);
        self::assertTrue($this->weeklyMailOn());

        $money = $this->owner->post('/' . self::SLUG . '/settings/money', $this->moneyAsStored());
        self::assertSees('Money saved.', $money);
        $still = $this->owner->get('/' . self::SLUG . '/settings/building');
        self::assertMatchesRegularExpression('/id="vendor_weekly_mail_enabled"[^>]*checked/', $still->body);
        self::assertTrue($this->weeklyMailOn());

        $off = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building(true, false));
        self::assertSees('Building saved.', $off);
        self::assertDoesNotMatchRegularExpression('/id="vendor_weekly_mail_enabled"[^>]*checked/', $off->body);
        self::assertFalse($this->weeklyMailOn());
    }

    public function testAClosedPortalRefusesSignInPreviewAndConsignorEntry(): void
    {
        $this->saveBuilding(true, true);
        $vendor = $this->vendor('Ada', 'ada-' . bin2hex(random_bytes(3)) . '@example.test', '40');
        $item = $this->staffItem($vendor['id'], 'Portal coat ' . bin2hex(random_bytes(2)), '2026-09-01', '1');
        $login = $this->vendorLogin($vendor['id']);
        $open = $login['client']->get('/' . self::SLUG . '/vendor');
        self::assertSees('Your check', $open);
        $preview = $this->owner->get('/' . self::SLUG . '/vendor?as=' . $vendor['id']);
        self::assertSees('Your check', $preview);

        $this->saveBuilding(false, true);
        $ended = $login['client']->get('/' . self::SLUG . '/vendor');
        self::assertSees('The vendor portal is off.', $ended);
        self::assertStringNotContainsString('Your check', $ended->body);
        $before = $this->countSql('SELECT count(*) FROM platform.email_sign_in_failures WHERE email = ?', [$login['email']]);
        $again = $login['client']->signInByEmail($login['email'], 'vendor-pass');
        self::assertSees('The vendor portal is off.', $again);
        self::assertSame($before, $this->countSql('SELECT count(*) FROM platform.email_sign_in_failures WHERE email = ?', [$login['email']]));

        $preview = $this->owner->get('/' . self::SLUG . '/vendor?as=' . $vendor['id']);
        self::assertSame(404, $preview->status);

        self::clearMailbox();
        self::flushMail();
        self::clearMailbox();
        $guest = self::client();
        $refused = $guest->post('/' . self::SLUG . '/consignor/enter', ['email' => $vendor['email']]);
        self::assertSame(404, $refused->status);
        self::flushMail();
        self::assertStringNotContainsString('/consignor/enter/', self::mailbox());

        $this->saveBuilding(true, true);
        $guest->get('/' . self::SLUG . '/consignor/enter');
        $sent = $guest->post('/' . self::SLUG . '/consignor/enter', ['email' => $vendor['email']]);
        self::assertSees(VendorEntry::SENT, $sent);
        self::flushMail();
        $token = $this->match(self::mailbox(), '#/consignor/enter/([0-9a-f]{64})#');
        $confirm = $guest->get('/' . self::SLUG . '/consignor/enter/' . $token);
        self::assertSees('Open my account', $confirm);
        $this->saveBuilding(false, false);
        $blocked = $guest->post('/' . self::SLUG . '/consignor/enter/' . $token, []);
        self::assertSame(404, $blocked->status);
        $this->saveBuilding(true, true);
        $home = $guest->post('/' . self::SLUG . '/consignor/enter/' . $token, []);
        self::assertStringContainsString($item['description'], $home->body);
        $this->saveBuilding(false, false);
        $signedOut = $guest->get('/' . self::SLUG . '/consignor');
        self::assertSame(404, $signedOut->status);
        $this->saveBuilding(true, false);
        $cleared = $guest->get('/' . self::SLUG . '/consignor');
        self::assertStringNotContainsString($item['description'], $cleared->body);
    }

    public function testInventoryOffKeepsTheCheckAndClosesTheItemPages(): void
    {
        $this->saveBuilding(true, false);
        $vendor = $this->vendor('Bea', 'bea-' . bin2hex(random_bytes(3)) . '@example.test', '40');
        $item = $this->staffItem($vendor['id'], 'Hidden coat ' . bin2hex(random_bytes(2)), '2026-09-01', '1');
        $login = $this->vendorLogin($vendor['id']);
        $check = $login['client']->get('/' . self::SLUG . '/vendor');
        $sales = $login['client']->get('/' . self::SLUG . '/vendor/sales');
        self::assertSees('Your check', $check);
        self::assertSees('Your sales', $sales);
        self::assertStringNotContainsString('/' . self::SLUG . '/vendor/inventory', $check->body);
        self::assertSame(404, $login['client']->get('/' . self::SLUG . '/vendor/inventory')->status);
        self::assertSame(404, $login['client']->get('/' . self::SLUG . '/vendor/labels')->status);
        self::assertSame(404, $login['client']->get('/' . self::SLUG . '/vendor/inventory/new')->status);
        self::assertSame(404, $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Should not save',
            'price' => '5.00',
            'quantity' => '1',
            'intake_on' => '2026-11-16',
            'placement' => 'consigned',
        ])->status);

        $this->saveBuilding(true, true);
        self::clearMailbox();
        self::flushMail();
        self::clearMailbox();
        $guest = self::client();
        $guest->get('/' . self::SLUG . '/consignor/enter');
        $guest->post('/' . self::SLUG . '/consignor/enter', ['email' => $vendor['email']]);
        self::flushMail();
        $token = $this->match(self::mailbox(), '#/consignor/enter/([0-9a-f]{64})#');
        $guest->get('/' . self::SLUG . '/consignor/enter/' . $token);
        $shown = $guest->post('/' . self::SLUG . '/consignor/enter/' . $token, []);
        self::assertStringContainsString($item['description'], $shown->body);
        self::assertStringContainsString('>Items<', $shown->body);
        $this->saveBuilding(true, false);
        $hidden = $guest->get('/' . self::SLUG . '/consignor');
        self::assertStringNotContainsString($item['description'], $hidden->body);
        self::assertStringNotContainsString('>Items<', $hidden->body);
        self::assertStringNotContainsString('About to expire', $hidden->body);
        self::assertStringContainsString('>Sales<', $hidden->body);
    }

    public function testAVendorAddsAnItemAndTheNextFormStaysOpen(): void
    {
        $today = $this->mallToday();
        $this->saveBuilding(true, true, '30');
        $other = $this->vendor('Cara', '', '10');
        $vendor = $this->vendor('Ada', '', '40');
        $login = $this->vendorLogin($vendor['id']);
        $home = $login['client']->get('/' . self::SLUG . '/vendor');
        self::assertStringContainsString('/' . self::SLUG . '/vendor/inventory', $home->body);
        $form = $login['client']->get('/' . self::SLUG . '/vendor/inventory/new?intake=' . $today);
        self::assertSees('New item', $form);
        self::assertStringContainsString('id="item-identity">Item', $form->body);
        self::assertStringContainsString('id="item-floor">Floor', $form->body);
        self::assertStringContainsString('vendor-pair', $form->body);
        self::assertMatchesRegularExpression('/id="description"[^>]*autofocus/', $form->body);
        $descriptionAt = strpos($form->body, 'id="description"');
        $priceAt = strpos($form->body, 'id="price"');
        $quantityAt = strpos($form->body, 'id="quantity"');
        $intakeAt = strpos($form->body, 'id="intake_on"');
        $expireAt = strpos($form->body, 'id="expires_on"');
        $brandAt = strpos($form->body, 'id="brand_id"');
        self::assertIsInt($descriptionAt);
        self::assertIsInt($priceAt);
        self::assertIsInt($quantityAt);
        self::assertIsInt($intakeAt);
        self::assertIsInt($expireAt);
        self::assertIsInt($brandAt);
        self::assertLessThan($priceAt, $descriptionAt);
        self::assertLessThan($quantityAt, $priceAt);
        self::assertLessThan($brandAt, $quantityAt);
        self::assertLessThan($intakeAt, $brandAt);
        self::assertLessThan($expireAt, $intakeAt);
        self::assertStringContainsString('value="' . $today . '"', $form->body);
        $expire = $this->shiftDate($today, 30);
        self::assertStringContainsString('value="' . $expire . '"', $form->body);

        $description = 'Live vase ' . bin2hex(random_bytes(3));
        $created = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new?intake=' . $today, [
            'description' => $description,
            'price' => '12.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => $expire,
            'placement' => 'consigned',
            'store_take' => '99',
            'code' => 'HACK',
            'barcode' => 'HACK',
            'vendor_id' => $other['id'],
        ]);
        self::assertSees('Item created.', $created);
        self::assertSees('New item', $created);
        self::assertStringContainsString('name="intake_on"', $created->body);
        self::assertStringContainsString('value="' . $today . '"', $created->body);
        $id = $this->match($created->body, '/name="item" value="([0-9a-fA-F-]{36})"/');
        $row = $this->itemRow($id);
        $stamp = str_replace('-', '', $today);
        self::assertMatchesRegularExpression('/^TG' . $stamp . '[0-9]{4}$/', $row['code']);
        self::assertMatchesRegularExpression('/^BC' . $stamp . '[0-9]{4}$/', $row['barcode']);
        self::assertNull($row['commission_bps']);
        self::assertSame($vendor['id'], $row['vendor_id']);
        self::assertSame('consigned', $row['placement']);
        self::assertSame($expire, $row['expires_on']);

        $open = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Open expire ' . bin2hex(random_bytes(2)),
            'price' => '8.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => '',
            'placement' => 'consigned',
        ]);
        self::assertSees('Item created.', $open);
        $openId = $this->match($open->body, '/name="item" value="([0-9a-fA-F-]{36})"/');
        self::assertNull($this->itemRow($openId)['expires_on']);

        $store = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Store piece ' . bin2hex(random_bytes(2)),
            'price' => '8.00',
            'quantity' => '1',
            'intake_on' => $today,
            'placement' => 'store',
        ]);
        self::assertSees('Choose where this piece sits.', $store);

        $early = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Early expire ' . bin2hex(random_bytes(2)),
            'price' => '8.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => $this->shiftDate($today, -1),
            'placement' => 'consigned',
        ]);
        self::assertSees('The expire date is before the intake date.', $early);

        $stranger = $login['client']->get('/' . self::SLUG . '/vendor/inventory/' . $this->staffItem($other['id'], 'Other vase ' . bin2hex(random_bytes(2)), $today, '1')['id'] . '/edit');
        self::assertSame(404, $stranger->status);
    }

    public function testBoothPlacementFollowsTheOpenStay(): void
    {
        $today = $this->mallToday();
        $this->saveBuilding(true, true);
        $vendor = $this->vendor('Dana', '', '40');
        $login = $this->vendorLogin($vendor['id']);
        $refused = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'No stay ' . bin2hex(random_bytes(2)),
            'price' => '9.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => $this->shiftDate($today, 10),
            'placement' => 'booth',
        ]);
        self::assertSees('a booth piece needs an open assignment', $refused);

        $this->assign($vendor['id']);
        $description = 'Booth bowl ' . bin2hex(random_bytes(2));
        $created = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => $description,
            'price' => '9.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => $this->shiftDate($today, 10),
            'placement' => 'booth',
        ]);
        self::assertSees('Item created.', $created);
        $row = $this->itemRow($this->match($created->body, '/name="item" value="([0-9a-fA-F-]{36})"/'));
        self::assertSame('booth', $row['placement']);
        self::assertNull($row['expires_on']);
    }

    public function testEditDeleteAndSaleLocks(): void
    {
        $today = $this->mallToday();
        $this->saveBuilding(true, true);
        $this->owner->ensurePeriod(self::SLUG);
        $this->openRegister();
        $vendor = $this->vendor('Eve', '', '40');
        $login = $this->vendorLogin($vendor['id']);

        $loose = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Loose bowl ' . bin2hex(random_bytes(2)),
            'price' => '6.00',
            'quantity' => '1',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSees('Item created.', $loose);
        $looseId = $this->match($loose->body, '/name="item" value="([0-9a-fA-F-]{36})"/');
        $journals = $this->countSql('SELECT count(*) FROM ' . $this->schema() . '.journals', []);
        $dispositions = $this->countSql(
            'SELECT count(*) FROM ' . $this->schema() . '.item_dispositions WHERE item_id = ?::uuid',
            [$looseId]
        );
        $pulled = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $looseId, [
            'description' => 'Loose bowl pulled',
            'price' => '6.00',
            'quantity' => '0',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSees('Item saved.', $pulled);
        self::assertSame(0, (int) $this->itemRow($looseId)['quantity']);
        self::assertSame($journals, $this->countSql('SELECT count(*) FROM ' . $this->schema() . '.journals', []));
        self::assertSame($dispositions, $this->countSql(
            'SELECT count(*) FROM ' . $this->schema() . '.item_dispositions WHERE item_id = ?::uuid',
            [$looseId]
        ));
        $deleted = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $looseId . '/delete', []);
        self::assertSees('Item deleted.', $deleted);
        self::assertStringNotContainsString(
            'Loose bowl pulled',
            ItemListBody::html($login['client'], '/' . self::SLUG . '/vendor/inventory?q=Loose+bowl+pulled'),
        );

        $description = 'Sold bowl ' . bin2hex(random_bytes(2));
        $created = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => $description,
            'price' => '10.00',
            'quantity' => '2',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSees('Item created.', $created);
        $id = $this->match($created->body, '/name="item" value="([0-9a-fA-F-]{36})"/');
        $code = $this->itemRow($id)['code'];
        $this->payCard($code, $description);
        $priced = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $id, [
            'description' => $description,
            'price' => '20.00',
            'quantity' => '1',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSees('That price is already on a sale.', $priced);
        $renamed = 'Sold bowl renamed ' . bin2hex(random_bytes(2));
        $kept = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $id, [
            'description' => $renamed,
            'price' => '10.00',
            'quantity' => '1',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSees('Item saved.', $kept);
        self::assertSees($renamed, $kept);
        self::assertSame(1, (int) $this->itemRow($id)['quantity']);
        $zero = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $id, [
            'description' => $renamed,
            'price' => '10.00',
            'quantity' => '0',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSame(0, (int) $this->itemRow($id)['quantity']);
        self::assertSees('That item is sold.', $zero);
        $blocked = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $id, [
            'description' => $renamed . ' again',
            'price' => '10.00',
            'quantity' => '1',
            'intake_on' => $today,
            'placement' => 'consigned',
        ]);
        self::assertSees('That item is sold.', $blocked);
        $remove = $login['client']->post('/' . self::SLUG . '/vendor/inventory/' . $id . '/delete', []);
        self::assertSees('That item is in use.', $remove);
    }

    public function testTheListColorsRowsByDaysOnTheFloor(): void
    {
        $this->saveBuilding(true, true);
        $vendor = $this->vendor('Faye', '', '40');
        $login = $this->vendorLogin($vendor['id']);
        $token = 'age' . bin2hex(random_bytes(3));
        $today = $this->mallToday();
        $bands = [
            0 => '0',
            30 => '0',
            31 => '1',
            60 => '1',
            61 => '2',
            90 => '2',
            91 => '3',
        ];
        foreach ($bands as $days => $tier) {
            $this->staffItem($vendor['id'], $token . ' day ' . $days, $this->shiftDate($today, -$days), '1');
        }
        $this->staffItem($vendor['id'], $token . ' future', $this->shiftDate($today, 1), '1');
        $pulled = $this->staffItem($vendor['id'], $token . ' zero', $today, '1');
        $this->owner->post('/' . self::SLUG . '/inventory/' . $pulled['id'], [
            'code' => $pulled['code'],
            'description' => $token . ' zero',
            'vendor_id' => $vendor['id'],
            'brand_id' => '',
            'price' => '4.00',
            'quantity' => '0',
            'barcode' => $pulled['barcode'],
            'intake_on' => $today,
        ]);

        $list = $login['client']->get('/' . self::SLUG . '/vendor/inventory?q=' . rawurlencode($token));
        self::assertSees('Row colors', $list);
        self::assertSees('Available items are colored by how many days they have been on the floor.', $list);
        self::assertSees('Days on floor', $list);
        $rows = ItemListBody::html($login['client'], '/' . self::SLUG . '/vendor/inventory?q=' . rawurlencode($token));
        foreach ($bands as $days => $tier) {
            $this->assertColoredRow($rows, $token . ' day ' . $days, $tier, (string) $days);
        }
        $this->assertColoredRow($rows, $token . ' future', '0', '0');
        $this->assertPlainRow($rows, $token . ' zero');
        $staff = $this->owner->get('/' . self::SLUG . '/inventory?q=' . rawurlencode($token));
        self::assertStringNotContainsString('Row colors', $staff->body);
    }

    public function testItemFormsSearchNamesAndRefuseANameThatWasNotAdded(): void
    {
        $this->saveBuilding(true, true, '30');
        $staffForm = $this->owner->get('/' . self::SLUG . '/inventory/new');
        self::assertStringContainsString('id="item-take">Take and tax', $staffForm->body);
        self::assertStringNotContainsString('id="item-price"', $staffForm->body);
        self::assertStringContainsString('id="vendor_suggest"', $staffForm->body);
        self::assertStringContainsString('data-search="/' . self::SLUG . '/inventory/brands.json"', $staffForm->body);
        self::assertStringContainsString('name-list.js', $staffForm->body);

        $stem = 'N' . bin2hex(random_bytes(3));
        $near = $this->jsonId($this->owner->post('/' . self::SLUG . '/inventory/brands', ['name' => $stem . 'en'])->body);
        $added = $this->jsonId($this->owner->post('/' . self::SLUG . '/inventory/brands', ['name' => $stem])->body);
        self::assertNotSame($near, $added);
        $colorNear = $this->jsonId($this->owner->post('/' . self::SLUG . '/inventory/colors', ['name' => $stem . ' blue'])->body);
        $colorAdded = $this->jsonId($this->owner->post('/' . self::SLUG . '/inventory/colors', ['name' => $stem . ' red'])->body);
        self::assertNotSame($colorNear, $colorAdded);

        $vendor = $this->vendor('Nell', '', '40');
        $picked = $this->owner->post('/' . self::SLUG . '/inventory', [
            'description' => 'Picked ' . $stem,
            'vendor_id' => $vendor['id'],
            'brand_id' => $near,
            'brand_label' => $stem . 'en',
            'color_id' => $colorNear,
            'color_label' => $stem . ' blue',
            'price' => '5.00',
            'quantity' => '1',
            'intake_on' => '2026-09-01',
            'placement' => 'consigned',
        ]);
        self::assertSees('Item created.', $picked);
        $pickedId = strtolower($this->match($picked->body, '/name="item" value="([0-9a-fA-F-]{36})"/'));
        $pickedNames = $this->itemNames($pickedId);
        self::assertSame($near, $pickedNames['brand_id']);
        self::assertSame($colorNear, $pickedNames['color_id']);

        $loose = $this->owner->post('/' . self::SLUG . '/inventory', [
            'description' => 'Loose ' . $stem,
            'vendor_id' => $vendor['id'],
            'brand_id' => '',
            'brand_label' => $stem . 'ish',
            'color_id' => '',
            'color_label' => $stem . ' teal',
            'price' => '5.00',
            'quantity' => '1',
            'intake_on' => '2026-09-01',
            'placement' => 'consigned',
        ]);
        self::assertSees('Add that brand before saving the item.', $loose);
        self::assertStringContainsString('value="' . $stem . 'ish"', $loose->body);

        $login = $this->vendorLogin($vendor['id']);
        $vendorForm = $login['client']->get('/' . self::SLUG . '/vendor/inventory/new');
        self::assertStringContainsString('data-name-list', $vendorForm->body);
        self::assertStringContainsString('id="brand_label"', $vendorForm->body);
        self::assertStringContainsString('name-list.js', $vendorForm->body);
        self::assertStringNotContainsString('<select class="select" id="brand_id"', $vendorForm->body);
        self::assertSame(404, $login['client']->get('/' . self::SLUG . '/inventory')->status);
        self::assertSame(404, $login['client']->get('/' . self::SLUG . '/inventory/vendors.json')->status);

        $vendorBrand = $this->jsonId($login['client']->post('/' . self::SLUG . '/inventory/brands', ['name' => $stem . ' vendor'])->body);
        $search = $login['client']->get('/' . self::SLUG . '/inventory/brands.json?q=' . rawurlencode($stem . ' vendor'));
        self::assertStringContainsString($vendorBrand, $search->body);
        $today = $this->mallToday();
        $saved = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Vendor ' . $stem,
            'price' => '6.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => $this->shiftDate($today, 30),
            'placement' => 'consigned',
            'brand_id' => $vendorBrand,
            'brand_label' => $stem . ' vendor',
            'color_id' => $colorAdded,
            'color_label' => $stem . ' red',
        ]);
        self::assertSees('Item created.', $saved);
        $savedId = strtolower($this->match($saved->body, '/name="item" value="([0-9a-fA-F-]{36})"/'));
        $savedNames = $this->itemNames($savedId);
        self::assertSame($vendorBrand, $savedNames['brand_id']);
        self::assertSame($colorAdded, $savedNames['color_id']);

        $refused = $login['client']->post('/' . self::SLUG . '/vendor/inventory/new', [
            'description' => 'Missing ' . $stem,
            'price' => '6.00',
            'quantity' => '1',
            'intake_on' => $today,
            'expires_on' => $this->shiftDate($today, 30),
            'placement' => 'consigned',
            'brand_id' => '',
            'brand_label' => $stem . ' missing',
        ]);
        self::assertSees('Add that brand before saving the item.', $refused);
        self::assertStringContainsString('value="' . $stem . ' missing"', $refused->body);
    }

    /**
     * @return array<string, string>
     */
    private function building(bool $portal, bool $inventory, string $floorDays = '', bool $weekly = false): array
    {
        $fields = [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'floor_days' => $floorDays,
            'use_color' => '1',
            'use_category' => '1',
            'use_size' => '1',
            'use_condition' => '1',
            'use_department' => '1',
            'use_brand' => '1',
        ];
        if ($portal) {
            $fields['vendor_portal_enabled'] = '1';
        }
        if ($inventory) {
            $fields['vendor_inventory_enabled'] = '1';
        }
        if ($weekly) {
            $fields['vendor_weekly_mail_enabled'] = '1';
        }

        return $fields;
    }

    private function weeklyMailOn(): bool
    {
        $statement = $this->pdo()->query('SELECT vendor_weekly_mail_enabled FROM ' . $this->schema() . '.mall');
        if ($statement === false) {
            self::fail('weekly mail flag missing');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            self::fail('weekly mail flag missing');
        }

        return MallGuard::flag($row['vendor_weekly_mail_enabled'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function moneyAsStored(): array
    {
        $statement = $this->pdo()->query(
            'SELECT commission_bps, tax_bps, cash_round_minor, operating_mode, store_stock_enabled, rent_prepaid_from_prior_sales, rent_cycle_day, require_second_signature FROM ' . $this->schema() . '.mall'
        );
        if ($statement === false) {
            self::fail('mall money row missing');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            self::fail('mall money row missing');
        }
        $mode = $row['operating_mode'] ?? null;
        $fields = [
            'default_store_take' => MallMeasure::percent((int) $row['commission_bps']),
            'tax_rate' => MallMeasure::taxPercent((int) $row['tax_bps']),
            'cash_round_minor' => (string) (int) $row['cash_round_minor'],
            'operating_mode' => is_string($mode) ? $mode : '',
            'shop_flags' => '1',
            'rent_cycle_day' => (string) (int) $row['rent_cycle_day'],
        ];
        if (MallGuard::flag($row['store_stock_enabled'] ?? null)) {
            $fields['store_stock_enabled'] = '1';
        }
        if (MallGuard::flag($row['rent_prepaid_from_prior_sales'] ?? null)) {
            $fields['rent_prepaid_from_prior_sales'] = '1';
        }
        if (MallGuard::flag($row['require_second_signature'] ?? null)) {
            $fields['require_second_signature'] = '1';
        }

        return $fields;
    }

    private function saveBuilding(bool $portal, bool $inventory, string $floorDays = ''): void
    {
        $saved = $this->owner->post('/' . self::SLUG . '/settings/building', $this->building($portal, $inventory, $floorDays));
        self::assertSees('Building saved.', $saved);
    }

    /**
     * @return array{id: string, email: string}
     */
    private function vendor(string $first, string $email, string $take): array
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $fields = [
            'code' => $code,
            'first_name' => $first,
            'last_name' => $code,
            'store_take' => $take,
        ];
        if ($email !== '') {
            $fields['email'] = $email;
        }
        $created = $this->owner->post('/' . self::SLUG . '/vendors', $fields);
        self::assertSees('Vendor created.', $created);

        return [
            'id' => strtolower($this->match($created->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#')),
            'email' => $email,
        ];
    }

    /**
     * @return array{client: FormClient, email: string}
     */
    private function vendorLogin(string $vendorId): array
    {
        $username = 'v' . bin2hex(random_bytes(4));
        $email = $username . '@example.test';
        $added = $this->owner->post('/' . self::SLUG . '/vendors/' . $vendorId . '/sign-in', [
            'username' => $username,
            'display_name' => 'Vendor ' . $username,
            'password' => 'vendor-pass',
            'email' => $email,
        ]);
        self::assertSees('Vendor sign-in added.', $added);
        $client = self::client();
        $home = $client->signIn(self::SLUG, $username, 'vendor-pass');
        self::assertSees('Your check', $home);

        return ['client' => $client, 'email' => $email];
    }

    /**
     * @return array{id: string, code: string, barcode: string, description: string}
     */
    private function staffItem(string $vendorId, string $description, string $intake, string $quantity): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $created = $this->owner->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '4.00',
            'quantity' => $quantity,
            'barcode' => 'BC-' . $code,
            'intake_on' => $intake,
            'placement' => 'consigned',
        ]);
        self::assertSees('Item created.', $created);
        $id = strtolower($this->match($created->body, '/name="item" value="([0-9a-fA-F-]{36})"/'));

        return [
            'id' => $id,
            'code' => $code,
            'barcode' => 'BC-' . $code,
            'description' => $description,
        ];
    }

    private function assign(string $vendorId): void
    {
        $code = 'b' . bin2hex(random_bytes(3));
        $created = $this->owner->post('/' . self::SLUG . '/booths', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees($code, $created);
        $booths = $this->owner->get('/' . self::SLUG . '/booths');
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $code);
        $rated = $this->owner->post('/' . self::SLUG . '/rates', [
            'booth_id' => $boothId,
            'amount' => '100.00',
        ]);
        self::assertSees('Asking rent saved.', $rated);
        $assigned = $this->owner->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        self::assertLessThan(400, $assigned->status, HtmlPage::error($assigned->body));
        self::assertSees('Booth assigned.', $assigned);
    }

    private function payCard(string $code, string $description): void
    {
        $this->owner->post('/' . self::SLUG . '/pos/discard', []);
        $this->owner->post('/' . self::SLUG . '/pos/new', []);
        $cart = $this->owner->post('/' . self::SLUG . '/pos/scan', ['scan' => $code]);
        self::assertSees($description, $cart);
        $this->owner->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'card',
            'tender_amount' => $this->money($cart->body, '/class="register-due">([^<]+)/'),
        ]);
        $paid = $this->owner->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $paid);
    }

    private function openRegister(): void
    {
        $page = $this->owner->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $active = $this->owner->post($use[1], []);
            self::assertSees('is the active register.', $active);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $counts = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $counts['count_' . $key] = '0';
        }
        $opened = $this->owner->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$counts,
        ]);
        self::assertSees('Close register', $opened);
    }

    /**
     * @return array{code: string, barcode: string, commission_bps: mixed, placement: string, expires_on: ?string, quantity: string, vendor_id: string}
     */
    private function jsonId(string $body): string
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        $id = $decoded['id'] ?? null;
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[0-9a-fA-F-]{36}$/', $id);

        return strtolower($id);
    }

    /**
     * @return array{brand_id: string, color_id: string}
     */
    private function itemNames(string $id): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT brand_id::text AS brand_id, color_id::text AS color_id FROM ' . $this->schema() . '.items WHERE id = ?::uuid'
        );
        self::assertNotFalse($statement);
        $statement->execute([$id]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $brandId = $row['brand_id'] ?? null;
        $colorId = $row['color_id'] ?? null;
        self::assertIsString($brandId);
        self::assertIsString($colorId);

        return [
            'brand_id' => strtolower($brandId),
            'color_id' => strtolower($colorId),
        ];
    }

    private function itemRow(string $id): array
    {
        $schema = $this->schema();
        $statement = $this->pdo()->prepare(
            'SELECT code, barcode, commission_bps, placement, expires_on::text AS expires_on, quantity::text AS quantity, vendor_id::text AS vendor_id
             FROM ' . $schema . '.items WHERE id = ?::uuid'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return [
            'code' => is_string($row['code'] ?? null) ? $row['code'] : '',
            'barcode' => is_string($row['barcode'] ?? null) ? $row['barcode'] : '',
            'commission_bps' => $row['commission_bps'] ?? null,
            'placement' => is_string($row['placement'] ?? null) ? $row['placement'] : '',
            'expires_on' => is_string($row['expires_on'] ?? null) ? $row['expires_on'] : null,
            'quantity' => is_string($row['quantity'] ?? null) ? $row['quantity'] : '',
            'vendor_id' => is_string($row['vendor_id'] ?? null) ? $row['vendor_id'] : '',
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function countSql(string $sql, array $params): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
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

    private function mallToday(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
    }

    private function shiftDate(string $day, int $days): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        self::assertInstanceOf(\DateTimeImmutable::class, $date);

        return $date->modify(($days < 0 ? '' : '+') . $days . ' days')->format('Y-m-d');
    }

    private function assertColoredRow(string $html, string $description, string $tier, string $days): void
    {
        $pattern = '#<tr class="vendor-item-row--age-' . $tier . '" id="item-[0-9a-f-]{36}">(?:(?!</tr>).)*'
            . preg_quote($description, '#')
            . '</td>(?:(?!</tr>).)*class="num vendor-days">\s*' . $days . '\s*</span>#s';
        self::assertSame(1, preg_match($pattern, $html), $description);
    }

    private function assertPlainRow(string $html, string $description): void
    {
        $pattern = '#<tr id="item-[0-9a-f-]{36}">(?:(?!</tr>).)*'
            . preg_quote($description, '#')
            . '</td>(?:(?!</tr>).)*class="num vendor-days">\s*<span class="muted">—</span>#s';
        self::assertSame(1, preg_match($pattern, $html), $description);
    }

    private function money(string $html, string $pattern): string
    {
        $shown = $this->match($html, $pattern);
        self::assertSame(1, preg_match('/\$([0-9]+\.[0-9]{2})/', $shown, $money));

        return $money[1];
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $found), $pattern);

        return $found[1];
    }
}
