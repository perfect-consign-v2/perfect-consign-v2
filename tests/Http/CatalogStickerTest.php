<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class CatalogStickerTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
        $saved = $this->client->post('/' . self::SLUG . '/settings', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'default_store_take' => '0',
            'use_color' => '1',
            'use_category' => '1',
            'use_size' => '1',
            'use_condition' => '1',
            'use_department' => '1',
        ]);
        self::assertSees('Settings saved.', $saved);
    }

    public function testAColorCanBeRenamedAndDeleted(): void
    {
        $name = 'cove-' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/colors', ['name' => $name]);
        self::assertSees('Color created.', $created);
        self::assertSees($name, $created);
        $id = HtmlPage::taggedId($created->body, 'tr', 'color', $name);
        $opened = $this->client->get('/' . self::SLUG . '/colors/new');
        self::assertSees('New color', $opened);
        $editor = $this->client->get('/' . self::SLUG . '/colors/' . $id . '/edit');
        self::assertSees($name, $editor);
        $renamed = $name . '-2';
        $saved = $this->client->post('/' . self::SLUG . '/colors/' . $id, ['name' => $renamed]);
        self::assertSees('Color saved.', $saved);
        $blank = $this->client->post('/' . self::SLUG . '/colors', ['name' => '']);
        self::assertSees('Enter a color name.', $blank);
        $duplicate = $this->client->post('/' . self::SLUG . '/colors', ['name' => $renamed]);
        self::assertSees('That name is already used.', $duplicate);
        $found = $this->client->get('/' . self::SLUG . '/colors?q=' . rawurlencode($renamed));
        self::assertSees($renamed, $found);
        $json = $this->client->get('/' . self::SLUG . '/inventory/colors.json?q=' . rawurlencode($renamed));
        self::assertStringContainsString($renamed, $json->body);
        $added = $this->client->post('/' . self::SLUG . '/inventory/colors', ['name' => $renamed . '-json']);
        self::assertStringContainsString($renamed . '-json', $added->body);
        $blankJson = $this->client->post('/' . self::SLUG . '/inventory/colors', ['name' => '']);
        self::assertStringContainsString('Enter a color name.', $blankJson->body);
        $departments = $this->client->get('/' . self::SLUG . '/departments');
        self::assertSame(200, $departments->status);
        self::assertSees('Departments', $departments);
        $category = 'cove-cat-' . bin2hex(random_bytes(2));
        $listed = $this->client->post('/' . self::SLUG . '/categories', ['name' => $category]);
        self::assertSees('Category created.', $listed);
        self::assertSees('No categories match.', $this->client->get('/' . self::SLUG . '/categories?q=zzzz-no-such-category'));
        $categoryId = HtmlPage::taggedId($listed->body, 'tr', 'category', $category);
        $removed = $this->client->post('/' . self::SLUG . '/categories/' . $categoryId . '/delete', []);
        self::assertSees('Category deleted.', $removed);
        $missing = '01900000-0000-7000-8000-0000000000bb';
        $unknown = $this->client->post('/' . self::SLUG . '/colors/' . $missing, ['name' => 'Gone']);
        self::assertSees('That color is not in this mall.', $unknown);
        $asked = $this->client->get('/' . self::SLUG . '/colors?confirm=' . $id);
        self::assertSees('Delete this color?', $asked);
        $gone = $this->client->post('/' . self::SLUG . '/colors/' . $id . '/delete', []);
        self::assertSees('Color deleted.', $gone);
    }

    public function testAPiecePrintsOnTheChosenStickers(): void
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        self::assertSees($code, $vendor);
        $directory = $this->client->get('/' . self::SLUG . '/parties');
        $vendorId = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $code);
        $color = 'cove-ink-' . bin2hex(random_bytes(2));
        $painted = $this->client->post('/' . self::SLUG . '/colors', ['name' => $color]);
        self::assertSees('Color created.', $painted);
        $colorId = HtmlPage::taggedId($painted->body, 'tr', 'color', $color);
        $tag = 's' . bin2hex(random_bytes(3));
        $barcode = 'BC' . $tag . str_repeat('A', 24);
        $description = 'Sticker ' . $tag;
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '12.00',
            'barcode' => $barcode,
            'intake_on' => '2026-09-01',
            'color_id' => '',
            'color_label' => $color,
        ]);
        self::assertSees('Item created.', $created);
        $list = $this->client->get('/' . self::SLUG . '/inventory?q=' . rawurlencode($tag));
        $itemId = HtmlPage::taggedId($list->body, 'tr', 'item', $description);
        $printed = $this->client->post('/' . self::SLUG . '/inventory/labels', [
            'stock' => 'avery_8160',
            'copies' => '2',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $itemId,
        ]);
        self::assertSees('Print stickers', $printed);
        self::assertSees($barcode, $printed);
        self::assertStringNotContainsString('data-selection-storage-key', $printed->body);
        $jewelry = $this->client->post('/' . self::SLUG . '/inventory/labels', [
            'stock' => 'bcw_jewelry',
            'copies' => '1',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $itemId,
        ]);
        self::assertSees($barcode, $jewelry);
        $refused = $this->client->post('/' . self::SLUG . '/inventory/labels', [
            'stock' => 'avery_8160',
            'copies' => '0',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $itemId,
        ]);
        self::assertSees('How many is a number from 1 to 999.', $refused);
        self::assertSees('These bars may be hard to scan', $jewelry);
        $held = $this->client->post('/' . self::SLUG . '/colors/' . $colorId . '/delete', []);
        self::assertSees('That color is in use.', $held);
        $saved = $this->client->post('/' . self::SLUG . '/inventory/' . $itemId, [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '12.00',
            'quantity' => '1',
            'barcode' => $barcode,
            'intake_on' => '2026-09-01',
            'color_id' => $colorId,
            'color_label' => $color,
        ]);
        self::assertSees($description, $saved);
        $gone = '01900000-0000-7000-8000-0000000000cc';
        $missingPiece = $this->client->post('/' . self::SLUG . '/inventory/labels', [
            'stock' => 'avery_8160',
            'copies' => '1',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $gone,
        ]);
        self::assertSees('That piece is not in this mall.', $missingPiece);
        $kept = $this->client->post('/' . self::SLUG . '/inventory/labels', [
            'stock' => 'avery_8160',
            'copies' => '1',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $itemId,
            'selection_storage_key' => 'pc.inventorySelection.v1.pieces.' . self::SLUG,
        ]);
        self::assertSees('Print stickers', $kept);
        self::assertStringContainsString(
            'data-selection-storage-key="pc.inventorySelection.v1.pieces.' . self::SLUG . '"',
            $kept->body,
        );
        $dropped = $this->client->post('/' . self::SLUG . '/inventory/labels', [
            'stock' => 'avery_8160',
            'copies' => '1',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $itemId,
            'selection_storage_key' => 'pc.inventorySelection.v1.pieces.' . self::SLUG . '.extra',
        ]);
        self::assertSees('Print stickers', $dropped);
        self::assertStringNotContainsString('data-selection-storage-key', $dropped->body);
    }
}
