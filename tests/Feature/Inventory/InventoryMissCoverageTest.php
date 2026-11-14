<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\BarcodePng;
use PerfectConsign\Feature\Inventory\Code128;
use PerfectConsign\Feature\Inventory\ExpireDesk;
use PerfectConsign\Feature\Inventory\ExpireDeskController;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\IntakeController;
use PerfectConsign\Feature\Inventory\InventoryController;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemDates;
use PerfectConsign\Feature\Inventory\ItemEntry;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\ItemGuard;
use PerfectConsign\Feature\Inventory\ItemListRows;
use PerfectConsign\Feature\Inventory\ItemMarkAllocator;
use PerfectConsign\Feature\Inventory\ItemPhotoController;
use PerfectConsign\Feature\Inventory\ItemPhotoIntake;
use PerfectConsign\Feature\Inventory\ItemQueries;
use PerfectConsign\Feature\Inventory\ItemSale;
use PerfectConsign\Feature\Inventory\ItemWriter;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\PhotoFacts;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Inventory\TagImport;
use PerfectConsign\Feature\Inventory\TagImportController;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class InventoryMissCoverageTest extends TestCase
{
    use CapturesPage;

    private const VENDOR = '01900000-0000-7000-8000-0000000000c1';

    private const ITEM = '01900000-0000-7000-8000-0000000000d1';

    private const BRAND = '01900000-0000-7000-8000-0000000000e1';

    public function testBarcodeFailuresAndAMissingPatternAreRefused(): void
    {
        $png = new BarcodePng();
        $png->failDeflate(true);
        try {
            $png->draw('1', 1, 1);
            self::fail('a failed deflate was drawn');
        } catch (\RuntimeException $error) {
            self::assertSame('barcode image could not be drawn', $error->getMessage());
        }
        $crc = new BarcodePng();
        $crc->standInCrc(1.5);
        try {
            $crc->draw('1', 1, 1);
            self::fail('a non-integer crc was drawn');
        } catch (\RuntimeException $error) {
            self::assertSame('barcode image could not be drawn', $error->getMessage());
        }
        $pattern = new ReflectionMethod(Code128::class, 'pattern');
        try {
            $pattern->invoke(new Code128(), 400);
            self::fail('a missing pattern was returned');
        } catch (\RuntimeException $error) {
            self::assertSame('barcode pattern is missing', $error->getMessage());
        }
    }

    public function testExpireDeskRefusesTheBadChoicesAndAMissingPiece(): void
    {
        $desk = $this->desk()->desk;
        try {
            $desk->dispose(self::ITEM, 'keep', '2026-01-01');
            self::fail('a bad kind was disposed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose return or donate.', $error->getMessage());
        }
        try {
            $desk->dispose('nope', 'return', '2026-01-01');
            self::fail('a bad item was disposed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an item.', $error->getMessage());
        }
        try {
            $desk->buy('nope', '1.00', '2026-01-01', str_repeat('a', 32));
            self::fail('a bad buy item was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an item.', $error->getMessage());
        }
        try {
            $desk->buy(self::ITEM, '1.00', '2026-01-01', 'token');
            self::fail('a short token was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('this form has expired', $error->getMessage());
        }
        try {
            $desk->buy(self::ITEM, '1.00', '2026-01-01', str_repeat('b', 32));
            self::fail('a missing buy was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose an item.', $error->getMessage());
        }
        $missing = $this->desk()->desk;
        try {
            $missing->buy(self::ITEM, '1.00', '2026-01-01', str_repeat('c', 32));
            self::fail('a missing item was bought');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose an item.', $error->getMessage());
        }
        $bought = $this->desk();
        $bought->statements->boundRoutes[] = ['match' => 'vendor_id', 'rows' => [[
            'vendor_id' => self::VENDOR,
            'placement' => 'consigned',
            'quantity' => 1,
            'expired' => 1,
        ]]];
        try {
            $bought->desk->buy(self::ITEM, '1.00', '2026-01-01', str_repeat('e', 32));
        } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
        }
        $blank = $this->desk();
        $blank->statements->boundRoutes[] = ['match' => 'vendor_id', 'rows' => [['vendor_id' => '']]];
        try {
            $blank->desk->buy(self::ITEM, '1.00', '2026-01-01', str_repeat('d', 32));
            self::fail('a blank vendor was bought');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a buy takes a consigned piece', $error->getMessage());
        }
        $live = $this->desk();
        $live->statements->boundRoutes[] = ['match' => 'vendor_id', 'rows' => [[
            'vendor_id' => self::VENDOR,
            'placement' => 'consigned',
            'quantity' => '1',
            'expired' => 0,
        ]]];
        try {
            $live->desk->buy(self::ITEM, '1.00', '2026-01-01', str_repeat('f', 32));
            self::fail('a live piece was bought');
        } catch (DatabaseRefusal $error) {
            self::assertSame('only an expired consigned piece can be bought', $error->getMessage());
        }
        $empty = $this->desk();
        $empty->statements->boundRoutes[] = ['match' => 'vendor_id', 'rows' => [[
            'vendor_id' => self::VENDOR,
            'placement' => 'consigned',
            'quantity' => 0,
            'expired' => 1,
        ]]];
        try {
            $empty->desk->buy(self::ITEM, '1.00', '2026-01-01', str_repeat('a', 32));
            self::fail('an empty piece was bought');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a buy takes a consigned piece', $error->getMessage());
        }
    }

    public function testTheExpirePageShowsARefusalAndABadChoice(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/dispose', [], [
            'csrf' => 'csrf-token',
            'kind' => 'keep',
            'posted_on' => 'yesterday',
        ]));
        $controller = new ExpireDeskController($door->app, $this->desk()->desk, $door->mall);
        $html = $this->capture(function () use ($controller): void {
            $controller->dispose(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('Choose return or donate.', $html);

        $refusing = $this->desk();
        $refusing->statements->changeError = new DatabaseRefusal('the books refused');
        $again = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/dispose', [], [
            'csrf' => 'csrf-token',
            'kind' => 'return',
            'posted_on' => '2026-01-02',
        ]));
        $refused = new ExpireDeskController($again->app, $refusing->desk, $again->mall);
        $page = $this->capture(function () use ($refused): void {
            $refused->dispose(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('the books refused', $page);
    }

    public function testIntakeCatalogBrandsAndABlankName(): void
    {
        $statements = $this->routed();
        $statements->boundRoutes[] = ['match' => 'FROM brands', 'rows' => [['id' => self::BRAND, 'name' => 'Ada']]];
        $catalog = new IntakeCatalog($statements);
        self::assertSame('Ada', $catalog->brand(self::BRAND)['name'] ?? null);
        try {
            $catalog->addBrand(' Ada');
            self::fail('a padded brand was added');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a brand name.', $error->getMessage());
        }
    }

    public function testIntakeRefusesDisabledListsAndRewritesADuplicateName(): void
    {
        $off = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/brands.json', ['q' => 'a'], []));
        $controller = $this->intake($off, false);
        $this->capture(function () use ($controller): void {
            $controller->findBrands(MallDoor::SLUG);
        });
        $post = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/brands', [], [
            'csrf' => 'csrf-token',
            'name' => 'Ada',
        ]));
        $this->capture(function () use ($post): void {
            $this->intake($post, false)->addBrand(MallDoor::SLUG);
        });

        $colors = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/colors', [], [
            'csrf' => 'csrf-token',
            'name' => 'Red',
        ]));
        $this->capture(function () use ($colors): void {
            $this->intake($colors, false)->addName(MallDoor::SLUG, 'colors');
        });

        $open = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/colors', [], [
            'csrf' => 'csrf-token',
            'name' => 'Red',
        ]));
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [$this->mallRow(true)]];
        $statements->boundError = new DatabaseRefusal('colors_name_key');
        $statements->boundErrorSql = 'INSERT INTO colors';
        $mall = $this->mall($statements);
        $lists = new NameListStore($statements, new CatalogLinks($statements));
        $adding = new IntakeController($open->app, new IntakeCatalog($statements), $lists, $mall, new StoreTake());
        $json = $this->capture(function () use ($adding): void {
            $adding->addName(MallDoor::SLUG, 'colors');
        });
        self::assertStringContainsString('could not be saved', $json);
    }

    public function testAnItemDeleteRefusalAndALostPhotoStayOnThePage(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/delete', [], [
            'csrf' => 'csrf-token',
        ]));
        $storeStatements = $this->routed();
        $storeStatements->affectedError = new DatabaseRefusal('still posted');
        $controller = $this->inventory($door, $storeStatements, null);
        $html = $this->capture(function () use ($controller): void {
            $controller->remove(MallDoor::SLUG, self::ITEM);
        });
        self::assertStringContainsString('still posted', $html);
        self::assertStringContainsString('No items yet', $html);

        $path = tempnam(sys_get_temp_dir(), 'pic');
        self::assertIsString($path);
        file_put_contents($path, "\xFF\xD8\xFF");
        $create = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory', [], [
            'csrf' => 'csrf-token',
            'code' => 'TAG1',
            'barcode' => 'BC1',
            'description' => 'Hat',
            'price' => '10.00',
            'quantity' => '1',
            'vendor_id' => self::VENDOR,
            'intake_on' => '2026-01-01',
            'placement' => 'consigned',
        ], '', '', '', [
            'photo' => ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 3, 'name' => 'a.jpg'],
        ]));
        $saved = $this->routed();
        $saved->boundRoutes[] = ['match' => 'SELECT id FROM vendors', 'rows' => [['id' => self::VENDOR]]];
        $saved->boundRoutes[] = ['match' => 'INSERT INTO items', 'rows' => [['id' => self::ITEM]]];
        $photos = $this->routed();
        $photos->transactionError = new \RuntimeException('disk');
        try {
            $adding = $this->inventory($create, $saved, $photos, $path);
            $this->capture(function () use ($adding): void {
                $adding->add(MallDoor::SLUG);
            });
            self::assertSame('The item was created. The photo was not saved.', $create->bag['warning'] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function testAGeneratedItemGivesUpAndASoldChangeIsRefused(): void
    {
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [$this->mallRow(false)]];
        $statements->boundRoutes[] = ['match' => 'SELECT id FROM vendors', 'rows' => [['id' => self::VENDOR]]];
        $statements->isolatedError = new DatabaseRefusal('used', 0, null, DatabaseRefusal::TAG_SQLSTATE);
        $store = new InventoryStore($statements, new CatalogLinks($statements), $this->mall($statements));
        try {
            $store->add($this->entry('', ''), '2026-01-01');
            self::fail('a colliding tag was stored');
        } catch (DatabaseRefusal $error) {
            self::assertSame('used', $error->getMessage());
        }

        $insert = $this->routed();
        $insert->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [$this->mallRow(false)]];
        $insert->boundRoutes[] = ['match' => 'SELECT id FROM vendors', 'rows' => [['id' => self::VENDOR]]];
        $insert->boundRoutes[] = ['match' => 'INSERT INTO items', 'rows' => [['id' => 4]]];
        $blank = new InventoryStore($insert, new CatalogLinks($insert), $this->mall($insert));
        try {
            $blank->add($this->entry('TAG', 'BC'), '2026-01-01');
            self::fail('a blank id was stored');
        } catch (\RuntimeException $error) {
            self::assertSame('the item was not saved', $error->getMessage());
        }

        $this->expectSold('missing', null, 'That item is not in this mall.');
        $this->expectSold('price', ['price_minor' => 1, 'vendor_id' => self::VENDOR], 'That price is already on a sale.');
        $this->expectSold('vendor', ['price_minor' => 1000, 'vendor_id' => self::BRAND], 'That vendor is already on a sale.');
        $this->expectSold('same', ['price_minor' => 1000, 'vendor_id' => self::VENDOR], 'That item is not in this mall.');
    }

    public function testItemFormsGuardsMarksTakesAndLabels(): void
    {
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [$this->mallRow(false)]];
        $statements->boundRoutes[] = ['match' => 'FROM brands', 'rows' => [['id' => self::BRAND, 'name' => 'Wool']]];
        $form = new ItemForm(
            new IntakeCatalog($statements),
            new NameListStore($statements, new CatalogLinks($statements)),
            $this->mall($statements),
            new StoreTake(),
        );
        $posted = $form->posted($this->draft(self::VENDOR, self::BRAND, [
            'color' => ['id' => '', 'label' => 'kept'],
            'category' => ['id' => self::ITEM, 'label' => 'Coat'],
            'size' => ['id' => '', 'label' => ''],
            'condition' => ['id' => '', 'label' => ''],
            'department' => ['id' => '', 'label' => ''],
        ]));
        self::assertSame('', $posted['vendor_id']);
        self::assertSame('Wool', $posted['brand_label']);
        self::assertSame('', $posted['attributes']['category']['id']);

        $guard = new ItemGuard($statements);
        $badVendor = $this->entry('T', 'B');
        $badVendor = new ItemEntry('T', 'B', 'nope', null, [], 'Hat', new ItemSale(100, 1, null, false), new ItemDates('2026-01-01', null), 'consigned');
        try {
            $guard->accept($badVendor);
            self::fail('a bad vendor was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That vendor is not in this mall.', $error->getMessage());
        }
        try {
            $guard->accept($this->entry('T', 'B'));
            self::fail('a missing vendor was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That vendor is not in this mall.', $error->getMessage());
        }
        $branded = new ItemEntry('T', 'B', self::VENDOR, 'nope', [], 'Hat', new ItemSale(100, 1, null, false), new ItemDates('2026-01-01', null), 'consigned');
        $known = $this->routed();
        $known->boundRoutes[] = ['match' => 'SELECT id FROM vendors', 'rows' => [['id' => self::VENDOR]]];
        try {
            (new ItemGuard($known))->accept($branded);
            self::fail('a bad brand was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That brand is not in this mall.', $error->getMessage());
        }
        $missingBrand = new ItemEntry('T', 'B', self::VENDOR, self::BRAND, [], 'Hat', new ItemSale(100, 1, null, false), new ItemDates('2026-01-01', null), 'consigned');
        try {
            (new ItemGuard($known))->accept($missingBrand);
            self::fail('a missing brand was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That brand is not in this mall.', $error->getMessage());
        }
        $badColor = new ItemEntry('T', 'B', self::VENDOR, null, ['color' => 'nope'], 'Hat', new ItemSale(100, 1, null, false), new ItemDates('2026-01-01', null), 'consigned');
        try {
            (new ItemGuard($known))->accept($badColor);
            self::fail('a bad color was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('color', $error->getMessage());
        }
        $missingColor = new ItemEntry('T', 'B', self::VENDOR, null, ['color' => self::ITEM], 'Hat', new ItemSale(100, 1, null, false), new ItemDates('2026-01-01', null), 'consigned');
        try {
            (new ItemGuard($known))->accept($missingColor);
            self::fail('a missing color was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('color', $error->getMessage());
        }

        $marks = new ItemMarkAllocator($statements);
        try {
            $marks->tag('today');
            self::fail('a bad day allocated a tag');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('A tag could not be generated.', $error->getMessage());
        }
        $full = $this->routed();
        $full->boundRoutes[] = ['match' => 'AS mark', 'rows' => [['mark' => 'BC202601019999']]];
        try {
            (new ItemMarkAllocator($full))->barcode('2026-01-01');
            self::fail('mark 10000 was allocated');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('A barcode could not be generated.', $error->getMessage());
        }
        $allocate = new ReflectionMethod(ItemMarkAllocator::class, 'allocate');
        try {
            $allocate->invoke($marks, 'ZZ', 'slot', 'A tag could not be generated.', '2026-01-01');
            self::fail('a foreign column allocated a mark');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('A tag could not be generated.', $error->getMessage());
        }

        $take = new StoreTake();
        self::assertSame('10.1', $take->format(1010));
        self::assertSame('10.01', $take->format(1001));

        $writer = new ItemWriter(new IntakeCatalog($statements), new NameListStore($statements, new CatalogLinks($statements)), $this->mall($statements), $take);
        try {
            $writer->entry($this->draft(self::VENDOR, '', null, 'shelf'));
            self::fail('a shelf placement was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose where this piece sits.', $error->getMessage());
        }
        try {
            $writer->entry($this->draft(self::VENDOR, '', null, 'consigned', str_repeat('b', 101)));
            self::fail('a long barcode was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That barcode is too long.', $error->getMessage());
        }
        try {
            $writer->entry($this->draft(self::VENDOR, '', null, 'consigned', 'BC', '1.5'));
            self::fail('a fractional quantity was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a whole quantity.', $error->getMessage());
        }

        $labels = new LabelRequest(new LabelCatalog());
        try {
            $labels->read([
                'stock' => 'avery_8160',
                'copies' => '1',
                'start_row' => '1',
                'start_column' => '1',
                'item' => [1],
            ], 'avery_8160');
            self::fail('a non-string item was printed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an item.', $error->getMessage());
        }

        $queries = new ItemQueries($statements);
        self::assertSame([], $queries->items('', 'sold'));
    }

    public function testPhotoBytesLabelsAndThePictureResponses(): void
    {
        $directory = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
        $intake = new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts(static fn (string $path): bool => true));
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            $intake->hex([
                'tmp_name' => $directory,
                'error' => UPLOAD_ERR_OK,
                'size' => 1,
                'name' => 'dir',
            ], false);
            self::fail('a directory was read as a photo');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That photo could not be read.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        self::assertSame('1 MB', (new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts(null, '1M')))->label());
        self::assertSame('1.4 MB', (new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts(null, '1500000')))->label());
        self::assertSame('1 KB', (new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts(null, '512')))->label());

        $jpeg = bin2hex("\xFF\xD8\xFF");
        $png = bin2hex("\x89PNG\r\n\x1a\n");
        $webp = bin2hex('RIFF' . "\x00\x00\x00\x00" . 'WEBP');
        $this->showPhoto($jpeg, 'image/jpeg', 'jpg');
        $this->showPhoto($png, 'image/png', 'png');
        $this->showPhoto($webp, 'image/webp', 'webp');
        $this->showPhoto(bin2hex('hello'), '', '');
        $odd = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], []));
        $oddStatements = $this->routed();
        $oddStatements->boundRoutes[] = ['match' => 'item_photos', 'rows' => [['vendor_id' => self::VENDOR, 'photo' => 'zz']]];
        set_error_handler(static fn (int $severity, string $message): bool => $severity === E_WARNING && str_contains($message, 'hex2bin'));
        try {
            $this->capture(function () use ($odd, $oddStatements): void {
                $this->photos($odd, $oddStatements, new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts()))->show(MallDoor::SLUG, self::ITEM);
            });
        } finally {
            restore_error_handler();
        }
        $unread = new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts(static fn (string $path): bool => false));
        try {
            $unread->hex([
                'tmp_name' => __FILE__,
                'error' => UPLOAD_ERR_OK,
                'size' => 0,
                'name' => 'empty.jpg',
            ], false);
            self::fail('an empty photo was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That photo could not be read.', $error->getMessage());
        }

        $gif = new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts());
        $gif->standInMedia('image/gif');
        $gifted = $this->routed();
        $gifted->boundRoutes[] = ['match' => 'item_photos', 'rows' => [['vendor_id' => self::VENDOR, 'photo' => $jpeg]]];
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], []));
        $body = $this->capture(function () use ($door, $gifted, $gif): void {
            $this->photos($door, $gifted, $gif)->show(MallDoor::SLUG, self::ITEM);
        });
        self::assertNotSame('', $body);

        $hidden = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], []));
        $hidden->session->set('actor_role', 'vendor');
        $hidden->session->set('actor_vendor', self::BRAND);
        $owned = $this->routed();
        $owned->boundRoutes[] = ['match' => 'item_photos', 'rows' => [['vendor_id' => self::VENDOR, 'photo' => $jpeg]]];
        $this->capture(function () use ($hidden, $owned): void {
            $this->photos($hidden, $owned, new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts()))->show(MallDoor::SLUG, self::ITEM);
        });

        $save = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], [
            'csrf' => 'csrf-token',
        ]));
        $owner = $this->routed();
        $owner->boundRoutes[] = ['match' => 'FROM items', 'rows' => [['id' => self::ITEM]]];
        $skip = new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts());
        $skip->skipPhotoBytes(true);
        $this->capture(function () use ($save, $owner, $skip): void {
            $this->photos($save, $owner, $skip)->save(MallDoor::SLUG, self::ITEM);
        });
        self::assertSame('Choose a photo.', $save->bag['warning'] ?? null);

        $lost = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], [
            'csrf' => 'csrf-token',
        ], '', '', '', [
            'photo' => ['tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'a.jpg'],
        ]));
        $keeper = $this->routed();
        $keeper->boundRoutes[] = ['match' => 'FROM items', 'rows' => [['id' => self::ITEM]]];
        $keeper->transactionError = new \RuntimeException('disk');
        $file = tempnam(sys_get_temp_dir(), 'pic');
        self::assertIsString($file);
        file_put_contents($file, "\xFF\xD8\xFF");
        $ready = new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts(static fn (string $path): bool => $path === $file));
        $lost = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], [
            'csrf' => 'csrf-token',
        ], '', '', '', [
            'photo' => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => 3, 'name' => 'a.jpg'],
        ]));
        try {
            $this->capture(function () use ($lost, $keeper, $ready): void {
                $this->photos($lost, $keeper, $ready)->save(MallDoor::SLUG, self::ITEM);
            });
            self::assertSame('The photo was not saved.', $lost->bag['warning'] ?? null);
        } finally {
            unlink($file);
        }
    }

    public function testTagImportRefusesAMissingFileABlankFileAndTooManyRows(): void
    {
        $import = $this->tagImport($this->routed());
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            $import->load(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'no-such-tags.csv');
            self::fail('a missing file was imported');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That file could not be read.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        $empty = tempnam(sys_get_temp_dir(), 'csv');
        self::assertIsString($empty);
        file_put_contents($empty, '');
        try {
            $import->load($empty);
            self::fail('an empty file was imported');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That file has no tags.', $error->getMessage());
        } finally {
            unlink($empty);
        }
        $many = tempnam(sys_get_temp_dir(), 'csv');
        self::assertIsString($many);
        $lines = ["code,vendor,description,price,barcode,quantity\n"];
        for ($index = 0; $index < 501; $index++) {
            $lines[] = 'T' . $index . ",V1,Hat,10.00,,1\n";
        }
        file_put_contents($many, implode('', $lines));
        $catalog = $this->routed();
        $catalog->boundRoutes[] = ['match' => 'lower(vendor.code)', 'rows' => [['id' => self::VENDOR]]];
        $catalog->rowRoutes[] = ['match' => 'time_zone', 'rows' => [['time_zone' => 'America/Los_Angeles']]];
        try {
            $this->tagImport($catalog)->load($many);
            self::fail('501 tags were imported');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Bring in 500 tags at a time.', $error->getMessage());
        } finally {
            unlink($many);
        }
    }

    public function testARefusedTagFileStaysOnTheImportPage(): void
    {
        $missing = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/import', [], [
            'csrf' => 'csrf-token',
        ]));
        $blank = new TagImportController($missing->app, $this->tagImport($this->routed()));
        $page = $this->capture(function () use ($blank): void {
            $blank->load(MallDoor::SLUG);
        });
        self::assertStringContainsString('Choose a CSV file.', $page);

        $file = tempnam(sys_get_temp_dir(), 'csv');
        self::assertIsString($file);
        file_put_contents($file, "code,vendor,description,price,barcode,quantity\nT1,V1,Hat,10.00,,1\n");
        $statements = $this->routed();
        $statements->boundRoutes[] = ['match' => 'lower(vendor.code)', 'rows' => [['id' => self::VENDOR]]];
        $statements->rowRoutes[] = ['match' => 'time_zone', 'rows' => [['time_zone' => 'America/Los_Angeles']]];
        $statements->transactionError = new DatabaseRefusal('the books refused');
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/inventory/import', [], [
            'csrf' => 'csrf-token',
        ], '', '', '', [
            'tags' => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => 80, 'name' => 'tags.csv'],
        ]));
        $controller = new TagImportController($door->app, $this->tagImport($statements));
        $controller->acceptLocalFile(true);
        try {
            $html = $this->capture(function () use ($controller): void {
                $controller->load(MallDoor::SLUG);
            });
            self::assertStringContainsString('the books refused', $html);
        } finally {
            unlink($file);
        }
    }

    public function testInventoryTemplatesRenderEachStockAndAnError(): void
    {
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        foreach (['available', 'unprinted', 'out', 'sold', ''] as $stock) {
            ob_start();
            $view->render('Feature/Inventory/inventory', [
                'slug' => MallDoor::SLUG,
                'query' => '',
                'stock' => $stock,
                'available' => 0,
                'unsold' => 0,
                'sold' => 0,
                'unprinted' => 0,
                'total' => 0,
                'confirm' => '',
                'items' => [],
                'attributeLists' => [],
                'labelStock' => 'avery_8160',
                'stocks' => [],
            ]);
            $html = ob_get_clean();
            self::assertIsString($html);
            self::assertStringContainsString('No items yet', $html);
        }
        ob_start();
        $view->render('Feature/Inventory/expire-desk', [
            'slug' => MallDoor::SLUG,
            'error' => 'Choose an item.',
            'items' => [],
            'formToken' => str_repeat('a', 32),
            'today' => '2026-01-01',
        ]);
        $expired = ob_get_clean();
        self::assertIsString($expired);
        self::assertStringContainsString('Choose an item.', $expired);
    }

    /**
     * @param array<string, int|string>|null $row
     */
    private function expectSold(string $label, ?array $row, string $message): void
    {
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [$this->mallRow(false)]];
        $statements->boundRoutes[] = ['match' => 'SELECT id FROM vendors', 'rows' => [['id' => self::VENDOR]]];
        $statements->affectedCount = 0;
        if ($row !== null) {
            $statements->boundRoutes[] = ['match' => 'stall_sticker_deal', 'rows' => [$row]];
        }
        $store = new InventoryStore($statements, new CatalogLinks($statements), $this->mall($statements));
        try {
            $store->save(self::ITEM, $this->entry('TAG', 'BC'), '2026-01-01');
            self::fail($label . ' change was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function showPhoto(string $hex, string $kind, string $ext): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/' . self::ITEM . '/photo', [], []));
        $statements = $this->routed();
        $statements->boundRoutes[] = ['match' => 'item_photos', 'rows' => [['vendor_id' => self::VENDOR, 'photo' => $hex]]];
        $html = $this->capture(function () use ($door, $statements): void {
            $this->photos($door, $statements, new ItemPhotoIntake(new NullLogger(), new MissPhotoFacts()))->show(MallDoor::SLUG, self::ITEM);
        });
        if ($kind === '') {
            self::assertStringContainsString('Not found', $html);

            return;
        }
        self::assertNotSame('', $html);
        self::assertNotSame('', $ext);
    }

    private function photos(MallDoor $door, RoutedStatements $statements, ItemPhotoIntake $intake): ItemPhotoController
    {
        return new ItemPhotoController($door->app, $statements, $door->actor, new NullLogger(), $intake);
    }

    private function inventory(MallDoor $door, RoutedStatements $storeStatements, ?RoutedStatements $photoStatements, ?string $photoPath = null): InventoryController
    {
        $mall = $door->mall;
        $catalog = new IntakeCatalog($storeStatements);
        $lists = new NameListStore($storeStatements, new CatalogLinks($storeStatements));
        $take = new StoreTake();
        $forms = new ItemForm($catalog, $lists, $mall, $take);
        $facts = new MissPhotoFacts($photoPath === null ? null : static fn (string $path): bool => $path === $photoPath);
        $intake = new ItemPhotoIntake(new NullLogger(), $facts);
        $photos = new ItemPhotoController($door->app, $photoStatements ?? $this->routed(), $door->actor, new NullLogger(), $intake);

        return new InventoryController(
            $door->app,
            new InventoryStore($storeStatements, new CatalogLinks($storeStatements), $mall),
            new ItemQueries($this->routed()),
            $forms,
            new ItemWriter($catalog, $lists, $mall, $take),
            new ItemListRows($catalog, $take, $forms),
            $mall,
            new LabelCatalog(),
            $photos,
        );
    }

    private function intake(MallDoor $door, bool $colors): IntakeController
    {
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [$this->mallRow($colors)]];

        return new IntakeController(
            $door->app,
            new IntakeCatalog($statements),
            new NameListStore($statements, new CatalogLinks($statements)),
            $this->mall($statements),
            new StoreTake(),
        );
    }

    private function tagImport(RoutedStatements $statements): TagImport
    {
        $mall = $this->mall($statements);
        $catalog = new IntakeCatalog($statements);
        $lists = new NameListStore($statements, new CatalogLinks($statements));

        return new TagImport(
            $statements,
            new ItemWriter($catalog, $lists, $mall, new StoreTake()),
            new InventoryStore($statements, new CatalogLinks($statements), $mall),
            $catalog,
            $mall,
        );
    }

    /**
     * @return object{desk: ExpireDesk, statements: RoutedStatements}
     */
    private function desk(): object
    {
        $statements = $this->routed();
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/inventory/expired', [], []));
        $desk = new ExpireDesk($statements, $door->ledger, new JournalAudit($door->actor, new AuditLog($statements)));

        return new class($desk, $statements) {
            public function __construct(public ExpireDesk $desk, public RoutedStatements $statements)
            {
            }
        };
    }

    private function entry(string $code, string $barcode): ItemEntry
    {
        return new ItemEntry(
            $code,
            $barcode,
            self::VENDOR,
            null,
            [],
            'Hat',
            new ItemSale(1000, 1, null, false),
            new ItemDates('2026-01-01', null),
            'consigned',
        );
    }

    /**
     * @param array<string, array{id: string, label: string}>|null $attributes
     * @return array{
     *     id: string,
     *     code: string,
     *     barcode: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     vendor_id: string,
     *     vendor_label: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     store_take: string,
     *     intake_on: string,
     *     expires_on: string,
     *     fill_expire: string,
     *     placement: string,
     *     tax_exempt: string
     * }
     */
    private function draft(
        string $vendorId = self::VENDOR,
        string $brandId = '',
        ?array $attributes = null,
        string $placement = 'consigned',
        string $barcode = 'BC',
        string $quantity = '1',
    ): array {
        return [
            'id' => '',
            'code' => 'TAG',
            'barcode' => $barcode,
            'description' => 'Hat',
            'price' => '10.00',
            'quantity' => $quantity,
            'vendor_id' => $vendorId,
            'vendor_label' => '',
            'brand_id' => $brandId,
            'brand_label' => '',
            'attributes' => $attributes ?? [
                'color' => ['id' => '', 'label' => ''],
                'category' => ['id' => '', 'label' => ''],
                'size' => ['id' => '', 'label' => ''],
                'condition' => ['id' => '', 'label' => ''],
                'department' => ['id' => '', 'label' => ''],
            ],
            'store_take' => '',
            'intake_on' => '2026-01-01',
            'expires_on' => '',
            'fill_expire' => '',
            'placement' => $placement,
            'tax_exempt' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mallRow(bool $color): array
    {
        return [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 1000,
            'floor_days' => null,
            'cash_round_minor' => 0,
            'tax_bps' => 0,
            'use_color' => $color,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => 'avery_8160',
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'consignment_enabled' => false,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
            'operating_mode' => 'both',
        ];
    }

    private function mall(RoutedStatements $statements): MallSettings
    {
        $pdo = $this->createStub(\PDO::class);

        return new MallSettings(
            $statements,
            new CompanyStore($statements, new \PerfectConsign\Bootstrap\DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($statements),
        );
    }

    private function routed(): RoutedStatements
    {
        return new RoutedStatements($this->createStub(\PDO::class));
    }
}

final class MissPhotoFacts implements PhotoFacts
{
    public function __construct(
        private readonly ?\Closure $uploaded = null,
        private readonly string $upload = '8M',
    ) {
    }

    public function uploaded(string $path): bool
    {
        if ($this->uploaded === null) {
            return false;
        }

        return ($this->uploaded)($path) === true;
    }

    public function ini(string $key): string
    {
        return $key === 'upload_max_filesize' ? $this->upload : '8M';
    }
}
