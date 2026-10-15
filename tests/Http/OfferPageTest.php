<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class OfferPageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    public function testTheOwnerSavesEditsAndEndsADeal(): void
    {
        $owner = self::client();
        $owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $empty = $owner->get('/' . self::SLUG . '/offers');
        self::assertSees('Deals', $empty);
        self::assertSees('New deal', $empty);

        $form = $owner->get('/' . self::SLUG . '/offers/new');
        self::assertSees('New deal', $form);
        self::assertSees('Whole store', $form);
        $starts = $this->value($form->body, 'starts_on');
        $blank = $owner->post('/' . self::SLUG . '/offers', $this->deal($starts, ['name' => '']));
        self::assertSees('Enter a deal name of 80 characters or less.', $blank);

        $name = 'Store ' . bin2hex(random_bytes(3));
        $saved = $owner->post('/' . self::SLUG . '/offers', $this->deal($starts, [
            'name' => $name,
            'scope' => 'store',
            'funded_by' => 'store',
            'kind' => 'bundle',
            'threshold' => '3',
            'price' => '25.00',
        ]));
        self::assertSees('Deal saved.', $saved);
        self::assertSees($name, $saved);
        self::assertSees('Whole store', $saved);
        self::assertSees('3 for $25.00', $saved);
        self::assertSees('Store', $saved);
        $id = $this->match($saved->body, '#/offers/([0-9a-fA-F-]{36})">' . preg_quote($name, '#') . '#');

        $edit = $owner->get('/' . self::SLUG . '/offers/' . $id);
        self::assertSees('Edit deal', $edit);
        self::assertSees($name, $edit);
        $renamed = $name . ' plus';
        $updated = $owner->post('/' . self::SLUG . '/offers/' . $id, $this->deal($starts, [
            'name' => $renamed,
            'scope' => 'store',
            'funded_by' => 'store',
            'ends_on' => $starts,
        ]));
        self::assertSees('Deal saved.', $updated);
        self::assertSees($renamed, $updated);

        $missing = $owner->get('/' . self::SLUG . '/offers/01900000-0000-7000-8000-00000000abcd');
        self::assertSame(404, $missing->status);
        $foreign = $owner->post('/' . self::SLUG . '/offers/01900000-0000-7000-8000-00000000abcd/end', []);
        self::assertSees('That deal is not yours', $foreign);

        $ended = $owner->post('/' . self::SLUG . '/offers/' . $id . '/end', []);
        self::assertSees('Deal ended.', $ended);
        self::assertSees('Ended', $ended);

        $vendor = $this->vendor($owner);
        $item = $this->item($owner, $vendor['id']);
        $floor = $owner->post('/' . self::SLUG . '/offers', $this->deal($starts, [
            'name' => 'Missing ' . $item['code'],
            'scope' => 'item',
            'item_code' => 'missing-' . $item['code'],
            'vendor_id' => $vendor['id'],
        ]));
        self::assertSees('That item is not on the floor.', $floor);
        $expensive = $owner->post('/' . self::SLUG . '/offers', $this->deal($starts, [
            'name' => 'High ' . $item['code'],
            'scope' => 'item',
            'kind' => 'unit_break',
            'item_code' => $item['code'],
            'price' => '25.00',
            'vendor_id' => $vendor['id'],
        ]));
        self::assertSees('the deal is not cheaper than the tag', $expensive);
        $itemDeal = 'Break ' . $item['code'];
        $priced = $owner->post('/' . self::SLUG . '/offers', $this->deal($starts, [
            'name' => $itemDeal,
            'scope' => 'item',
            'kind' => 'unit_break',
            'item_code' => $item['code'],
            'price' => '8.00',
            'vendor_id' => $vendor['id'],
        ]));
        self::assertSees('Deal saved.', $priced);
        self::assertSees('3 or more at $8.00 each', $priced);
    }

    public function testAVendorDealStaysOnThatVendor(): void
    {
        $owner = self::client();
        $owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $vendor = $this->vendor($owner);
        $item = $this->item($owner, $vendor['id']);
        $username = 'v' . bin2hex(random_bytes(4));
        $added = $owner->post('/' . self::SLUG . '/vendors/' . $vendor['id'] . '/sign-in', [
            'username' => $username,
            'display_name' => 'Deal ' . $vendor['code'],
            'password' => 'vendor-pass',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Vendor sign-in added.', $added);

        $client = self::client();
        $home = $client->signIn(self::SLUG, $username, 'vendor-pass');
        self::assertSame(200, $home->status);
        $list = $client->get('/' . self::SLUG . '/vendor/offers');
        self::assertSees('Deals', $list);
        $form = $client->get('/' . self::SLUG . '/vendor/offers/new');
        self::assertSees('All my items', $form);
        self::assertStringNotContainsString('Whole store', $form->body);
        $starts = $this->value($form->body, 'starts_on');
        $store = $client->post('/' . self::SLUG . '/vendor/offers', $this->deal($starts, [
            'name' => 'Storewide ' . $vendor['code'],
            'scope' => 'store',
        ]));
        self::assertSees('A vendor deal stays on that vendor', $store);

        $name = 'Mine ' . $vendor['code'];
        $saved = $client->post('/' . self::SLUG . '/vendor/offers', $this->deal($starts, [
            'name' => $name,
            'scope' => 'vendor',
            'kind' => 'bundle',
            'price' => '12.00',
        ]));
        self::assertSees('Deal saved.', $saved);
        self::assertSees($name, $saved);
        $id = $this->match($saved->body, '#/vendor/offers/([0-9a-fA-F-]{36})">' . preg_quote($name, '#') . '#');
        $edit = $client->get('/' . self::SLUG . '/vendor/offers/' . $id);
        self::assertSees('Edit deal', $edit);
        $ended = $client->post('/' . self::SLUG . '/vendor/offers/' . $id . '/end', []);
        self::assertSees('Deal ended.', $ended);

        $storeName = 'Owner store ' . $vendor['code'];
        $other = $owner->post('/' . self::SLUG . '/offers', $this->deal($starts, [
            'name' => $storeName,
            'scope' => 'store',
            'funded_by' => 'store',
        ]));
        self::assertSees('Deal saved.', $other);
        $ownerId = $this->match($other->body, '#/offers/([0-9a-fA-F-]{36})">' . preg_quote($storeName, '#') . '#');
        $blocked = $client->post('/' . self::SLUG . '/vendor/offers/' . $ownerId . '/end', []);
        self::assertSees('That deal is not yours', $blocked);
        $notMine = $client->post('/' . self::SLUG . '/vendor/offers', $this->deal($starts, [
            'name' => 'Borrowed ' . $item['code'],
            'scope' => 'item',
            'item_code' => 'no-such-' . $item['code'],
        ]));
        self::assertSees('That item is not on the floor.', $notMine);
    }

    /**
     * @param array<string, string> $replace
     * @return array<string, string>
     */
    private function deal(string $starts, array $replace): array
    {
        return array_replace([
            'name' => 'Deal',
            'scope' => 'store',
            'kind' => 'bundle',
            'threshold' => '3',
            'price' => '10.00',
            'funded_by' => 'vendor',
            'starts_on' => $starts,
            'ends_on' => '',
            'item_code' => '',
            'vendor_id' => '',
        ], $replace);
    }

    /**
     * @return array{id: string, code: string}
     */
    private function vendor(FormClient $owner): array
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $owner->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Nia',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $created);

        return [
            'id' => $this->match($created->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#'),
            'code' => $code,
        ];
    }

    /**
     * @return array{code: string}
     */
    private function item(FormClient $owner, string $vendorId): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $created = $owner->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => 'Deal item ' . $code,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '20.00',
            'quantity' => '1',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        return ['code' => $code];
    }

    private function value(string $html, string $id): string
    {
        return $this->match($html, '#id="' . $id . '"[^>]*value="([^"]*)"#');
    }

    private function match(string $html, string $pattern): string
    {
        $found = preg_match($pattern, $html, $matched);
        if ($found !== 1 || !isset($matched[1]) || !is_string($matched[1]) || $matched[1] === '') {
            self::fail('the page did not contain ' . $pattern);
        }

        return $matched[1];
    }
}
