<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Tests\Support\QuietAudit;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Brand\BrandController;
use PerfectConsign\Feature\Brand\BrandStore;
use PerfectConsign\Feature\Catalog\NameListController;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Inventory\ExpireDesk;
use PerfectConsign\Feature\Inventory\ExpireDeskController;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\ItemQueries;
use PerfectConsign\Feature\Inventory\ItemRowsController;
use PerfectConsign\Feature\Inventory\ItemWriter;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\AssignmentOffer;
use PerfectConsign\Feature\Party\BoothController;
use PerfectConsign\Feature\Party\BoothWrite;
use PerfectConsign\Feature\Party\BoothFields;
use PerfectConsign\Feature\Party\BoothListFilter;
use PerfectConsign\Feature\Party\BoothListStore;
use PerfectConsign\Feature\Party\BoothMeasures;
use PerfectConsign\Feature\Party\BoothRoster;
use PerfectConsign\Feature\Party\PartyController;
use PerfectConsign\Feature\Party\PartyRoleType;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorBalances;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Party\VendorForm;
use PerfectConsign\Feature\Party\VendorPage;
use PerfectConsign\Feature\Party\VendorPlacement as PartyVendorPlacement;
use PerfectConsign\Feature\Party\VendorRoster;
use PerfectConsign\Feature\Party\VendorYearPaid;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Feature\Vendor\VendorAccount;
use PerfectConsign\Feature\Vendor\VendorEntry;
use PerfectConsign\Feature\Vendor\VendorEntryController;
use PerfectConsign\Feature\Vendor\VendorHomeController;
use PerfectConsign\Feature\Vendor\VendorItemCatalog;
use PerfectConsign\Feature\Vendor\VendorItemController;
use PerfectConsign\Feature\Vendor\VendorItemDraft;
use PerfectConsign\Feature\Vendor\VendorPlacement;
use PerfectConsign\Feature\Vendor\VendorPortal;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailOutbox;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

final class ControllerLineCoverageTest extends TestCase
{
    use CapturesPage;

    private const ITEM = '01900000-0000-7000-8000-0000000000b1';

    private const VENDOR = '01900000-0000-7000-8000-0000000000c1';

    public function testABadActiveFlagIsShownOnTheBrandAndNameLists(): void
    {
        $brandDoor = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/brands/' . self::ITEM . '/active', [
            'active' => '2',
        ]));
        $brandDoor->statements->useBrand = true;
        $statements = new RoutedStatements(new PDO('sqlite::memory:'));
        $brands = $this->capture(function () use ($brandDoor, $statements): void {
            (new BrandController($brandDoor->app, new BrandStore($statements, new CatalogLinks($statements)), $brandDoor->mall))
                ->active(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('Choose active or inactive.', $brands);

        $nameDoor = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/colors/' . self::ITEM . '/active', [
            'active' => '2',
        ]));
        $nameDoor->statements->useColor = true;
        $names = $this->capture(function () use ($nameDoor, $statements): void {
            (new NameListController($nameDoor->app, new NameListStore($statements, new CatalogLinks($statements)), $nameDoor->mall))
                ->active(MallDoor::SLUG, 'colors', self::ITEM);
        });
        self::assertStringContainsString('Choose active or inactive.', $names);

        $mergeDoor = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/colors/' . self::ITEM . '/merge', [
            'target' => self::VENDOR,
        ]));
        $mergeDoor->statements->useColor = true;
        $merged = $this->capture(function () use ($mergeDoor, $statements): void {
            (new NameListController($mergeDoor->app, new NameListStore($statements, new CatalogLinks($statements)), $mergeDoor->mall))
                ->merge(MallDoor::SLUG, 'colors', self::ITEM);
        });
        self::assertStringContainsString('not in this mall', $merged);
    }

    public function testBoothActiveAndSaveShowTheRefusalOnTheForm(): void
    {
        $flag = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/booths/' . self::ITEM . '/active', [
            'active' => '2',
        ]));
        $flag->statements->rentals = true;
        $html = $this->capture(function () use ($flag): void {
            $this->booths($flag, new RoutedStatements(new PDO('sqlite::memory:')))->setActive(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('Choose active or inactive.', $html);

        $quiet = new RoutedStatements(new PDO('sqlite::memory:'));
        $quiet->affectedCount = 0;
        $missing = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/booths/' . self::ITEM . '/active', [
            'active' => '0',
            'back' => 'booth',
        ]));
        $missing->statements->rentals = true;
        $gone = $this->capture(function () use ($missing, $quiet): void {
            $this->booths($missing, $quiet)->setActive(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('that booth is not in this mall', $gone);

        $save = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/booths/' . self::ITEM, [
            'code' => '',
        ]));
        $save->statements->rentals = true;
        $save->statements->affectedCount = 0;
        $form = $this->capture(function () use ($save): void {
            $this->booths($save, new RoutedStatements(new PDO('sqlite::memory:')))->save(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('booth', strtolower($form));
    }

    public function testAPartyRedirectsForAMissingCustomerOrWaitListRecord(): void
    {
        $missing = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/parties/' . MallDoor::PARTY, [], []));
        $gone = $this->capture(function () use ($missing): void {
            $this->parties($missing, new FixedParties($missing->statements, $this->audit($missing), new AssignmentBook($missing->statements)))
                ->party(MallDoor::SLUG, MallDoor::PARTY);
        });
        self::assertStringContainsString('<h1>Not found</h1>', $gone);

        $customer = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/parties/' . MallDoor::PARTY, [], []));
        $customers = new FixedParties($customer->statements, $this->audit($customer), new AssignmentBook($customer->statements));
        $customers->party = $this->partyRecord([PartyRoleType::CUSTOMER]);
        try {
            $this->parties($customer, $customers)->party(MallDoor::SLUG, MallDoor::PARTY);
            self::fail('a customer party stayed on the party page');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
        }

        $waiting = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/parties/' . MallDoor::PARTY, [], []));
        $waitList = new FixedParties($waiting->statements, $this->audit($waiting), new AssignmentBook($waiting->statements));
        $waitList->party = $this->partyRecord([PartyRoleType::WAIT_LIST]);
        try {
            $this->parties($waiting, $waitList)->party(MallDoor::SLUG, MallDoor::PARTY);
            self::fail('a wait-list party stayed on the party page');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
        }
    }

    public function testAVendorSaveAndCustomerRoleShowTheStoreRefusal(): void
    {
        $save = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR));
        $parties = new FixedParties($save->statements, $this->audit($save), new AssignmentBook($save->statements));
        $parties->saveError = new DatabaseRefusal('the books refused');
        $html = $this->capture(function () use ($save, $parties): void {
            $this->parties($save, $parties)->save(MallDoor::SLUG, MallDoor::VENDOR);
        });
        self::assertStringContainsString('the books refused', $html);

        $role = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/customer-role'));
        $adding = new FixedParties($role->statements, $this->audit($role), new AssignmentBook($role->statements));
        $adding->roleError = new \InvalidArgumentException('that vendor is not in this mall');
        $refused = $this->capture(function () use ($role, $adding): void {
            $this->parties($role, $adding)->addCustomerRole(MallDoor::SLUG, MallDoor::VENDOR);
        });
        self::assertStringContainsString('<h1>Not found</h1>', $refused);
    }

    public function testBuyingAnExpiredItemShowsARefusalAndABadToken(): void
    {
        $desk = new ExpireDesk(new RoutedStatements(new PDO('sqlite::memory:')), MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/expired', [], []))->ledger, $this->audit(MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/expired', [], []))));
        $refused = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/buy', [
            'amount' => '1.00',
        ]));
        $html = $this->capture(function () use ($refused, $desk): void {
            (new ExpireDeskController($refused->app, $desk, $refused->mall))->buy(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('Choose an item.', $html);

        $stale = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/buy', [
            'amount' => '1.00',
        ]));
        $page = $this->capture(function () use ($stale, $desk): void {
            (new ExpireDeskController($stale->app, $desk, $stale->mall))->buy(MallDoor::SLUG, 'not-an-item');
        });
        self::assertStringContainsString('Choose an item.', $page);
    }

    public function testVendorRowsAndLabelsRefuseAClosedInventorySwitch(): void
    {
        $rows = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/vendor/inventory/rows', [], []));
        $rows->statements->portal = false;
        $controller = (new ReflectionClass(ItemRowsController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ItemRowsController::class, 'app'))->setValue($controller, $rows->app);
        (new ReflectionProperty(ItemRowsController::class, 'mall'))->setValue($controller, $rows->mall);
        $closedRows = $this->capture(function () use ($controller): void {
            $controller->vendor(MallDoor::SLUG);
        });
        self::assertStringContainsString('<h1>Not found</h1>', $closedRows);

        $labels = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendor/labels'));
        $labels->statements->portal = false;
        $home = (new ReflectionClass(VendorHomeController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(VendorHomeController::class, 'app'))->setValue($home, $labels->app);
        (new ReflectionProperty(VendorHomeController::class, 'mall'))->setValue($home, $labels->mall);
        $closedLabels = $this->capture(function () use ($home): void {
            $home->printLabels(MallDoor::SLUG);
        });
        self::assertStringContainsString('<h1>Not found</h1>', $closedLabels);
    }

    public function testAConsignorLinkPreferenceAndClosedPortal(): void
    {
        $token = str_repeat('ab', 32);
        $expired = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/consignor/enter/' . $token));
        try {
            $this->entry($expired, new RoutedStatements($expired->pdo))->redeem(MallDoor::SLUG, $token);
            self::fail('an empty token opened the account');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
            self::assertSame('That link has expired.', $expired->bag['notice'] ?? null);
        }

        $prefer = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/consignor/preference', [
            'payout_mode' => 'hold',
        ]));
        $now = (string) time();
        $prefer->bag['consignor:' . MallDoor::SLUG] = self::VENDOR;
        $prefer->bag['consignor_seen:' . MallDoor::SLUG] = $now;
        $prefer->bag['consignor_started:' . MallDoor::SLUG] = $now;
        $active = new RoutedStatements($prefer->pdo);
        $active->boundRoutes = [
            ['match' => 'SELECT active FROM vendors', 'rows' => [['active' => true]]],
        ];
        try {
            $this->entry($prefer, $active)->preference(MallDoor::SLUG);
            self::fail('the preference did not leave the page');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
            self::assertSame('Payout preference saved.', $prefer->bag['notice'] ?? null);
        }

        $closed = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/consignor/logout'));
        $closed->statements->portal = false;
        try {
            $this->entry($closed, new RoutedStatements($closed->pdo))->logout(MallDoor::SLUG);
            self::fail('a closed portal stayed on the sign-in page');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
            self::assertSame('The vendor portal is off.', $closed->bag['warning'] ?? null);
        }
    }

    public function testVendorItemSavesShowABookRefusalAndAMissingRow(): void
    {
        $rows = new RoutedStatements(new PDO('sqlite::memory:'));
        $rows->changeError = new DatabaseRefusal('the books refused');
        $rows->affectedError = new DatabaseRefusal('the books refused');
        $rows->boundError = new DatabaseRefusal('the books refused');
        $rows->boundErrorSql = 'INSERT INTO items';
        $rows->boundRoutes = [
            ['match' => 'FROM vendors WHERE id', 'rows' => [['id' => self::VENDOR]]],
            ['match' => 'AND vendor_id', 'rows' => [['id' => self::ITEM]]],
            ['match' => 'FROM items AS item', 'rows' => [[
                'id' => self::ITEM,
                'code' => 'A1',
                'barcode' => '',
                'description' => 'Hat',
                'price_minor' => 1000,
                'quantity' => 1,
                'vendor_id' => self::VENDOR,
                'vendor_code' => '',
                'vendor_name' => '',
                'placement' => 'consigned',
                'brand_id' => null,
                'commission_bps' => 1000,
                'tax_exempt' => false,
                'intake_on' => '2026-11-17',
                'expires_on' => '2026-12-17',
            ]]],
        ];
        $added = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendor/inventory/new', $this->itemPost()));
        $added->bag['actor_vendor'] = self::VENDOR;
        $created = $this->capture(function () use ($added, $rows): void {
            $this->items($added, $rows)->add(MallDoor::SLUG);
        });
        self::assertStringContainsString('the books refused', $created);

        $saved = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendor/inventory/' . self::ITEM, $this->itemPost()));
        $saved->bag['actor_vendor'] = self::VENDOR;
        $updated = $this->capture(function () use ($saved, $rows): void {
            $this->items($saved, $rows)->save(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('the books refused', $updated);

        $removed = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendor/inventory/' . self::ITEM . '/delete'));
        $removed->bag['actor_vendor'] = self::VENDOR;
        $deleted = $this->capture(function () use ($removed, $rows): void {
            $this->items($removed, $rows)->remove(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('the books refused', $deleted);

        $gone = new RoutedStatements(new PDO('sqlite::memory:'));
        $gone->boundRoutes = [
            ['match' => 'AND vendor_id', 'rows' => [['id' => self::ITEM]]],
        ];
        $missing = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendor/inventory/' . self::ITEM, $this->itemPost()));
        $missing->bag['actor_vendor'] = self::VENDOR;
        $page = $this->capture(function () use ($missing, $gone): void {
            $this->items($missing, $gone)->save(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('Not found', $page);
    }

    /**
     * @param array<string, string> $post
     */
    private function post(string $path, array $post = []): Request
    {
        return new Request('POST', $path, [], ['csrf' => 'csrf-token'] + $post);
    }

    private function audit(MallDoor $door): JournalAudit
    {
        return QuietAudit::journal($door->statements, $door->actor);
    }

    private function booths(MallDoor $door, RoutedStatements $lists): BoothController
    {
        $parties = new PartyStore($door->statements, $this->audit($door), new AssignmentBook($door->statements));

        return new BoothController(
            $door->app,
            $parties,
            $door->mall,
            new BoothRoster(new BoothMeasures()),
            new BoothListFilter(),
            new BoothListStore($lists),
            new BoothFields($door->app),
            new BoothWrite($parties, new AssignmentBook($door->statements), $this->audit($door)),
        );
    }

    private function parties(MallDoor $door, PartyStore $store): PartyController
    {
        $stays = new AssignmentBook($door->statements);
        $roster = new VendorRoster(new PartyVendorPlacement());
        $runs = new PayoutRunStore($door->statements, new OccupiedPack($door->statements));
        $balances = new VendorBalances(
            new PayoutStore($door->pdo, $door->statements, $runs, new PayoutWindow()),
            new VendorClawback($door->ledger, $door->statements),
            new VendorYearPaid($door->statements),
        );
        $forms = new VendorForm($door->app, $door->mall, new \PerfectConsign\Feature\Party\SellerPaths($door->mall));
        $staff = new StaffStore($door->statements, new RecoveryAddress(), new PasswordChoice());

        return new PartyController(
            $door->app,
            $store,
            $stays,
            $door->mall,
            $roster,
            $forms,
            new VendorPage($door->app, $store, $stays, $door->mall, $roster, $balances, new AssignmentOffer($store, $stays), $forms, $staff),
        );
    }

    /**
     * @param list<string> $roles
     * @return array{id: string, name: string, kind: string, roles: list<string>}
     */
    private function partyRecord(array $roles): array
    {
        return [
            'id' => MallDoor::PARTY,
            'name' => 'Pat',
            'kind' => 'person',
            'roles' => $roles,
        ];
    }

    private function entry(MallDoor $door, Statements $statements): VendorEntryController
    {
        return new VendorEntryController(
            $door->app,
            $door->session,
            new VendorEntry(
                $door->pdo,
                $door->roles,
                $statements,
                $this->createStub(LetterMailbox::class),
                new MailOutbox($door->pdo),
                $this->createStub(LoggerInterface::class),
                $door->app->request(),
            ),
            new VendorPortal($door->pdo, $door->roles, $statements),
            $door->mall,
        );
    }

    private function items(MallDoor $door, RoutedStatements $rows): VendorItemController
    {
        $store = new InventoryStore($rows, new CatalogLinks($rows), $door->mall, QuietAudit::make($rows));
        $form = new ItemForm(new IntakeCatalog($rows), new NameListStore($rows, new CatalogLinks($rows)), $door->mall, new StoreTake());

        return new VendorItemController(
            $door->app,
            new \PerfectConsign\Feature\Vendor\VendorPreview($door->app, $door->actor, new PartyStore($door->statements, $this->audit($door), new AssignmentBook($door->statements)), $door->mall),
            new VendorItemDraft(
                new ItemWriter(new IntakeCatalog($rows), new NameListStore($rows, new CatalogLinks($rows)), $door->mall, new StoreTake()),
                new StoreTake(),
                new VendorPlacement($rows, $door->mall),
                $store,
            ),
            $store,
            new VendorAccount($rows, new ItemQueries($rows), $form, new StoreTake()),
            new VendorItemCatalog($door->mall, new \PerfectConsign\Feature\Inventory\LabelCatalog()),
            new VendorPlacement($rows, $door->mall),
            $form,
        );
    }

    /**
     * @return array<string, string>
     */
    private function itemPost(): array
    {
        return [
            'description' => 'Hat',
            'price' => '10.00',
            'quantity' => '1',
            'intake_on' => '2026-11-17',
            'expires_on' => '2026-12-17',
            'placement' => 'consigned',
            'brand_id' => '',
            'brand_label' => '',
        ];
    }
}

final class FixedParties extends PartyStore
{
    /** @var array{id: string, name: string, kind: string, roles: list<string>}|null */
    public ?array $party = null;

    public ?\Throwable $saveError = null;

    public ?\Throwable $roleError = null;

    public function findParty(string $partyId): ?array
    {
        return $this->party;
    }

    public function saveVendor(string $id, VendorDraft $vendor): void
    {
        if ($this->saveError !== null) {
            throw $this->saveError;
        }
    }

    public function addCustomerRole(string $vendorId): string
    {
        if ($this->roleError !== null) {
            throw $this->roleError;
        }

        return $vendorId;
    }
}
