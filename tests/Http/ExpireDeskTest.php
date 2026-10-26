<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ExpireDeskTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testExpiredConsignedPiecesCanBeReturnedDonatedOrBought(): void
    {
        $slug = ProbeMall::SLUG;
        self::assertSees('Money saved.', $this->client->post('/' . $slug . '/settings', [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '0',
            'operating_mode' => 'both',
            'cash_round_minor' => '1',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
            'late_fee_grace_days' => '0',
            'store_stock_enabled' => '1',
            'shop_flags' => '1',
        ]));
        $tag = bin2hex(random_bytes(3));
        $vendorCode = 'v' . $tag;
        $created = $this->client->post('/' . $slug . '/vendors', [
            'code' => $vendorCode,
            'first_name' => 'Expire',
            'last_name' => $tag,
            'store_take' => '40',
            'expiry_action' => 'donate',
        ]);
        self::assertSees('Vendor created.', $created);
        $vendorId = HtmlPage::pathId($created->body, $slug . '/vendors');

        $returnCode = 'r' . $tag;
        $donateCode = 'd' . $tag;
        $buyCode = 'b' . $tag;
        foreach (
            [
                [$returnCode, 'Return me'],
                [$donateCode, 'Donate me'],
                [$buyCode, 'Buy me'],
            ] as [$code, $description]
        ) {
            $made = $this->client->post('/' . $slug . '/inventory', [
                'code' => $code,
                'description' => $description . ' ' . $tag,
                'vendor_id' => $vendorId,
                'brand_id' => '',
                'price' => '12.00',
                'barcode' => '',
                'intake_on' => '2026-08-01',
                'expires_on' => '2026-09-01',
                'placement' => 'consigned',
            ]);
            self::assertSees('Item created.', $made);
        }

        $desk = $this->client->get('/' . $slug . '/inventory/expired');
        self::assertSame(200, $desk->status);
        self::assertSees('Expired items', $desk);
        self::assertSees('Return me ' . $tag, $desk);
        self::assertSees('Donate me ' . $tag, $desk);
        self::assertSees('Buy me ' . $tag, $desk);

        $returnId = HtmlPage::taggedId($desk->body, 'tr', 'item', 'Return me ' . $tag);
        $donateId = HtmlPage::taggedId($desk->body, 'tr', 'item', 'Donate me ' . $tag);
        $buyId = HtmlPage::taggedId($desk->body, 'tr', 'item', 'Buy me ' . $tag);

        $returned = $this->client->post('/' . $slug . '/inventory/' . $returnId . '/dispose', [
            'kind' => 'return',
            'posted_on' => '2026-10-01',
            'form_token' => bin2hex(random_bytes(16)),
            'amount' => '12.00',
        ]);
        self::assertSees('Returned to the vendor.', $returned);

        $donated = $this->client->post('/' . $slug . '/inventory/' . $donateId . '/dispose', [
            'kind' => 'donate',
            'posted_on' => '2026-10-01',
            'form_token' => bin2hex(random_bytes(16)),
            'amount' => '12.00',
        ]);
        self::assertSees('Marked donated.', $donated);

        $bought = $this->client->post('/' . $slug . '/inventory/' . $buyId . '/dispose', [
            'kind' => 'buy',
            'posted_on' => '2026-10-01',
            'form_token' => bin2hex(random_bytes(16)),
            'amount' => '5.00',
        ]);
        self::assertSees('Bought for the store.', $bought);

        $cleared = $this->client->get('/' . $slug . '/inventory/expired');
        self::assertStringNotContainsString('Return me ' . $tag, $cleared->body);
        self::assertStringNotContainsString('Donate me ' . $tag, $cleared->body);
        self::assertStringNotContainsString('Buy me ' . $tag, $cleared->body);

        $inventory = $this->client->get('/' . $slug . '/inventory');
        self::assertSees('Expired items', $inventory);
    }
}
