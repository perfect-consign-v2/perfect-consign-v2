<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ItemListBody;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class StaffJournalOpeningTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
    }

    public function testACashierPasswordIsCheckedOnAddAndReplace(): void
    {
        $username = 'c' . bin2hex(random_bytes(4));
        $short = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'cashier',
            'display_name' => 'Cash',
            'username' => $username,
            'password' => 'short',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('at least 8 characters', $short);
        $added = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'cashier',
            'display_name' => 'Cash',
            'username' => $username,
            'password' => 'cashier1',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Cashier added.', $added);
        $again = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'cashier',
            'display_name' => 'Cash',
            'username' => $username,
            'password' => 'cashier1',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('That sign-in name is already used.', $again);
        $id = $this->match($added->body, '#/staff/([0-9a-fA-F-]{36})/password#');
        $bad = $this->client->post('/' . self::SLUG . '/staff/' . $id . '/password', [
            'password' => 'short',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('at least 8 characters', $bad);
        $missing = $this->client->post('/' . self::SLUG . '/staff/00000000-0000-7000-8000-000000000099/password', [
            'password' => 'cashier2',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Choose a cashier.', $missing);
    }

    public function testACashierCannotReuseTheOwnerEmail(): void
    {
        $username = 'c' . bin2hex(random_bytes(4));
        $page = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'cashier',
            'display_name' => 'Cash',
            'username' => $username,
            'password' => 'cashier1',
            'email' => ProbeMall::EMAIL,
        ]);
        self::assertSees('That email is already used on another sign-in.', $page);
    }

    public function testOpeningAndJournalEdges(): void
    {
        $opening = $this->client->get('/' . self::SLUG . '/opening');
        self::assertSees('Opening amounts', $opening);
        $today = ProbeMall::POSTING_DATE;
        $unbalanced = $this->client->post('/' . self::SLUG . '/opening', [
            'posting_date' => $today,
            'line_account_1' => 'not-an-id',
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
            'line_account_2' => 'not-an-id',
            'line_side_2' => 'credit',
            'line_amount_2' => '2.00',
        ]);
        self::assertLessThan(500, $unbalanced->status);
        $journals = $this->client->get('/' . self::SLUG . '/journals');
        self::assertSees('Money record', $journals);
        if (preg_match('#/journals/([0-9a-fA-F-]{36})#', $journals->body, $found) === 1) {
            $page = $this->client->get('/' . self::SLUG . '/journals/' . $found[1]);
            self::assertSees('Posted entry', $page);
            $voided = $this->client->post('/' . self::SLUG . '/journals/' . $found[1] . '/void', []);
            self::assertLessThan(500, $voided->status);
        }
        $missingBrand = $this->client->getWithoutRedirect(
            '/' . self::SLUG . '/brands/00000000-0000-7000-8000-0000000000b1/edit',
        );
        self::assertTrue(in_array($missingBrand->status, [404, 303], true));
        $blankBrand = $this->client->post('/' . self::SLUG . '/inventory/brands', ['name' => '']);
        self::assertSame(422, $blankBrand->status);
        self::assertStringContainsString('Enter a brand name.', $blankBrand->body);
        $colors = $this->client->get('/' . self::SLUG . '/inventory/colors.json?q=');
        self::assertSame(200, $colors->status);
    }

    public function testABrandInUseStaysOnTheItemUntilItIsMergedOrShownAgain(): void
    {
        $brand = 'Held ' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/brands', ['name' => $brand]);
        self::assertSees($brand, $created);
        $brandId = HtmlPage::taggedId($created->body, 'tr', 'brand', $brand);
        $code = 'v' . bin2hex(random_bytes(3));
        $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Bea',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/vendors')->body, 'tr', 'vendor', $code);
        $tag = 't' . bin2hex(random_bytes(3));
        $description = 'Piece ' . $tag;
        $item = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => $brandId,
            'price' => '4.00',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $item);
        $itemId = HtmlPage::taggedId(
            ItemListBody::html($this->client, '/' . self::SLUG . '/inventory?q=' . rawurlencode($tag)),
            'tr',
            'item',
            $description,
        );
        $asked = $this->client->get('/' . self::SLUG . '/brands?q=' . rawurlencode($brand) . '&confirm=' . $brandId);
        self::assertSees('Make inactive', $asked);
        self::assertStringNotContainsString('This brand is in use by 1 item. Are you sure you want to delete it?', $asked->body);
        self::assertStringNotContainsString('/brands/' . $brandId . '/delete', $asked->body);
        $deleted = $this->client->post('/' . self::SLUG . '/brands/' . $brandId . '/delete', []);
        self::assertSees('That brand is in use.', $deleted);
        $edit = $this->client->get('/' . self::SLUG . '/inventory/' . $itemId . '/edit');
        self::assertSees($description, $edit);
        self::assertStringContainsString($brandId, $edit->body);
        $hidden = $this->client->post('/' . self::SLUG . '/brands/' . $brandId . '/active', ['active' => '0']);
        self::assertSees('Brand is inactive.', $hidden);
        $quiet = $this->client->get('/' . self::SLUG . '/inventory/brands.json?q=' . rawurlencode($brand));
        self::assertStringNotContainsString($brandId, $quiet->body);
        self::assertStringContainsString($brandId, $this->client->get('/' . self::SLUG . '/inventory/' . $itemId . '/edit')->body);
        $shown = $this->client->post('/' . self::SLUG . '/brands/' . $brandId . '/active', ['active' => '1']);
        self::assertSees('Brand is active.', $shown);
        $offered = $this->client->get('/' . self::SLUG . '/inventory/brands.json?q=' . rawurlencode($brand));
        self::assertStringContainsString($brandId, $offered->body);
    }

    public function testAnInactiveColorStaysOnItsItemAndIsRefusedOnANewOne(): void
    {
        $color = 'Ink ' . bin2hex(random_bytes(3));
        $painted = $this->client->post('/' . self::SLUG . '/colors', ['name' => $color]);
        self::assertSees('Color created.', $painted);
        $colorId = HtmlPage::taggedId($painted->body, 'tr', 'color', $color);
        $code = 'v' . bin2hex(random_bytes(3));
        $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/vendors')->body, 'tr', 'vendor', $code);
        $tag = 'c' . bin2hex(random_bytes(3));
        $description = 'Dyed ' . $tag;
        $item = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '8.00',
            'intake_on' => '2026-09-01',
            'color_id' => $colorId,
            'color_label' => $color,
        ]);
        self::assertSees('Item created.', $item);
        $itemId = HtmlPage::taggedId(
            ItemListBody::html($this->client, '/' . self::SLUG . '/inventory?q=' . rawurlencode($tag)),
            'tr',
            'item',
            $description,
        );
        $hidden = $this->client->post('/' . self::SLUG . '/colors/' . $colorId . '/active', ['active' => '0']);
        self::assertSees('Color is inactive.', $hidden);
        $quiet = $this->client->get('/' . self::SLUG . '/inventory/colors.json?q=' . rawurlencode($color));
        self::assertStringNotContainsString($colorId, $quiet->body);
        $refused = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag . 'b',
            'description' => $description . ' again',
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '8.00',
            'intake_on' => '2026-09-01',
            'color_id' => $colorId,
            'color_label' => $color,
        ]);
        self::assertSees('That color is inactive.', $refused);
        $saved = $this->client->post('/' . self::SLUG . '/inventory/' . $itemId, [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '8.00',
            'quantity' => '1',
            'intake_on' => '2026-09-01',
            'color_id' => $colorId,
            'color_label' => $color,
        ]);
        self::assertSees('Item saved.', $saved);
        self::assertStringContainsString($colorId, $saved->body);
    }

    public function testMergingABrandMovesTheItemAndRemovesTheSource(): void
    {
        $held = 'Held ' . bin2hex(random_bytes(3));
        $keeper = 'Levi ' . bin2hex(random_bytes(3));
        $source = $this->client->post('/' . self::SLUG . '/brands', ['name' => $held]);
        self::assertSees($held, $source);
        $heldId = HtmlPage::taggedId($source->body, 'tr', 'brand', $held);
        $target = $this->client->post('/' . self::SLUG . '/brands', ['name' => $keeper]);
        $keeperId = HtmlPage::taggedId($target->body, 'tr', 'brand', $keeper);
        $asleep = $this->client->post('/' . self::SLUG . '/brands/' . $keeperId . '/active', ['active' => '0']);
        self::assertSees('Brand is inactive.', $asleep);
        $code = 'v' . bin2hex(random_bytes(3));
        $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Bea',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/vendors')->body, 'tr', 'vendor', $code);
        $tag = 'm' . bin2hex(random_bytes(3));
        $description = 'Merged ' . $tag;
        $item = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => $heldId,
            'price' => '4.00',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $item);
        $itemId = HtmlPage::taggedId(
            ItemListBody::html($this->client, '/' . self::SLUG . '/inventory?q=' . rawurlencode($tag)),
            'tr',
            'item',
            $description,
        );
        $same = $this->client->post('/' . self::SLUG . '/brands/' . $heldId . '/merge', ['target' => $heldId]);
        self::assertSees('Choose a different brand.', $same);
        $merged = $this->client->post('/' . self::SLUG . '/brands/' . $heldId . '/merge', ['target' => $keeperId]);
        self::assertSees('Brand merged.', $merged);
        self::assertStringNotContainsString($held, $this->client->get('/' . self::SLUG . '/brands?q=' . rawurlencode($held))->body);
        $edit = $this->client->get('/' . self::SLUG . '/inventory/' . $itemId . '/edit');
        self::assertStringContainsString($keeperId, $edit->body);
        self::assertStringNotContainsString($heldId, $edit->body);
        self::assertSees($keeper, $edit);
        $offered = $this->client->get('/' . self::SLUG . '/inventory/brands.json?q=' . rawurlencode($keeper));
        self::assertStringContainsString($keeperId, $offered->body);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $found), $pattern);

        return $found[1];
    }
}
