<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ItemListBody;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ItemRowsHttpTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testTheShellStaysEmptyAndTheRowsUrlReturnsTheMatch(): void
    {
        $slug = ProbeMall::SLUG;
        $code = 'r' . bin2hex(random_bytes(3));
        $vendor = $this->client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Rae',
            'last_name' => $code,
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . $slug . '/vendors')->body, 'tr', 'vendor', $code);
        $tag = 't' . bin2hex(random_bytes(3));
        $description = 'Paged coat ' . $tag;
        $created = $this->client->post('/' . $slug . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'price' => '8.00',
            'quantity' => '1',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        $shell = $this->client->get('/' . $slug . '/inventory?q=' . rawurlencode($tag));
        self::assertSame(200, $shell->status);
        self::assertStringContainsString('data-dt-server="1"', $shell->body);
        self::assertStringContainsString('<tbody></tbody>', $shell->body);
        self::assertStringNotContainsString('pdfmake.min.js', $shell->body);
        self::assertStringNotContainsString($description, $shell->body);

        $rows = ItemListBody::html($this->client, '/' . $slug . '/inventory?q=' . rawurlencode($tag));
        $itemId = HtmlPage::taggedId($rows, 'tr', 'item', $description);
        self::assertStringContainsString($description, $rows);

        $refused = $this->client->get('/' . $slug . '/inventory/rows?length=7');
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('That page size is not allowed.', $refused->body);

        $asked = $this->client->get('/' . $slug . '/inventory?q=' . rawurlencode($tag) . '&confirm=' . $itemId);
        self::assertStringContainsString('data-dt-start="0"', $asked->body);
        $prompt = ItemListBody::html(
            $this->client,
            '/' . $slug . '/inventory?q=' . rawurlencode($tag) . '&confirm=' . $itemId,
        );
        self::assertStringContainsString('Delete this item?', $prompt);
    }
}
