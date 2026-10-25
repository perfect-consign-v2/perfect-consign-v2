<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Vendor\VendorEntry;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class LedgerPathTest extends WebTestCase
{
    // request( open( page( prefer( form( submit( redeem( home( preference( save( sync( refusal( number( replay(

    private const SLUG = ProbeMall::SLUG;

    public function testARepeatedPayPostsOneJournalAndASnapKeepsItsNumber(): void
    {
        $client = self::client();
        $client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(self::SLUG);
        $this->openRegister($client);
        $client->post('/' . self::SLUG . '/pos/discard', []);
        $vendor = $this->vendor($client, 'ada-' . bin2hex(random_bytes(3)) . '@example.test');
        $second = $this->item($client, $vendor, 'Hat ' . bin2hex(random_bytes(2)));
        $secondId = $this->itemId($client, $second['code']);
        $item = $this->item($client, $vendor, 'Coat ' . bin2hex(random_bytes(2)));
        $scan = $client->postJson('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        self::assertSame(200, $scan->status);
        $ticket = $this->payload($scan->body);
        $postingKey = $ticket['ticket']['posting_key'] ?? '';
        self::assertIsString($postingKey);
        self::assertMatchesRegularExpression('/^sale:[0-9a-f-]{36}$/', $postingKey);
        $due = $ticket['ticket']['due'] ?? 0;
        self::assertIsInt($due);
        $client->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'cash',
            'tender_amount' => $this->dollars($due),
        ]);
        $paid = $client->postJson('/' . self::SLUG . '/pos/pay', ['posting_key' => $postingKey]);
        self::assertSame(200, $paid->status);
        $first = $this->payload($paid->body);
        $number = $first['journal_no'] ?? 0;
        self::assertGreaterThan(0, $number);
        $again = $client->postJson('/' . self::SLUG . '/pos/pay', ['posting_key' => $postingKey]);
        $second = $this->payload($again->body);
        self::assertSame($number, $second['journal_no'] ?? null, $again->status . ' ' . $again->body);

        $snapKey = $this->uuid();
        $held = $client->postJson('/' . self::SLUG . '/pos/snap', [
            'posting_key' => $snapKey,
            'item_id' => $this->uuid(),
            'price' => '10.00',
            'tax' => '0.00',
            'commission' => '0.00',
            'quantity' => '1',
            'commission_bps' => '0',
            'tax_bps' => '0',
        ]);
        self::assertSame(200, $held->status);
        self::assertStringContainsString('This sale is not posted.', $held->body);
        $refused = $client->postJson('/' . self::SLUG . '/pos/sync', ['posting_key' => $snapKey]);
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('a sale line needs an item', $refused->body);
        self::assertStringContainsString('This sale is not posted.', $refused->body);

        $postedKey = $this->uuid();
        $client->postJson('/' . self::SLUG . '/pos/snap', [
            'posting_key' => $postedKey,
            'item_id' => $secondId,
            'price' => '10.00',
            'tax' => '0.00',
            'commission' => '0.00',
            'quantity' => '1',
            'commission_bps' => '0',
            'tax_bps' => '0',
        ]);
        $synced = $client->postJson('/' . self::SLUG . '/pos/sync', ['posting_key' => $postedKey]);
        $sync = $this->payload($synced->body);
        self::assertSame(200, $synced->status);
        self::assertGreaterThan(0, $sync['journal_no'] ?? 0);
        $repeat = $client->postJson('/' . self::SLUG . '/pos/sync', ['posting_key' => $postedKey]);
        $repeatPayload = $this->payload($repeat->body);
        self::assertSame($sync['journal_no'], $repeatPayload['journal_no'] ?? null);
    }

    public function testAConsignorLinkShowsOnlyThatVendor(): void
    {
        $owner = self::client();
        $owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $adaMail = 'ada-' . bin2hex(random_bytes(3)) . '@example.test';
        $ada = $this->vendor($owner, $adaMail);
        $bea = $this->vendor($owner, '');
        $adaItem = $this->item($owner, $ada, 'Ada coat ' . bin2hex(random_bytes(2)));
        $beaItem = $this->item($owner, $bea, 'Bea coat ' . bin2hex(random_bytes(2)));

        self::clearMailbox();
        $guest = self::client();
        $guest->get('/' . self::SLUG . '/consignor/enter');
        $unknown = $guest->post('/' . self::SLUG . '/consignor/enter', ['email' => 'missing-' . bin2hex(random_bytes(3)) . '@example.test']);
        $known = $guest->post('/' . self::SLUG . '/consignor/enter', ['email' => $adaMail]);
        self::assertSees(VendorEntry::SENT, $unknown);
        self::assertSees(VendorEntry::SENT, $known);
        self::assertStringNotContainsString($adaMail, $unknown->body);
        self::assertStringNotContainsString($adaMail, $known->body);
        $token = $this->match(self::mailbox(), '#/consignor/enter/([0-9a-f]{64})#');
        $home = $guest->get('/' . self::SLUG . '/consignor/enter/' . $token);
        self::assertStringContainsString($adaItem['description'], $home->body);
        self::assertStringNotContainsString($beaItem['description'], $home->body);
        self::assertStringNotContainsString('Store credit', $home->body);
        $saved = $guest->post('/' . self::SLUG . '/consignor/preference', ['payout_mode' => 'hold']);
        self::assertSees('Hold', $saved);
    }

    private function openRegister(FormClient $client): void
    {
        $page = $client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $counts = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $bill) {
            $counts['count_' . $bill] = '0';
        }
        $client->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$counts,
        ]);
    }

    private function vendor(FormClient $client, string $email): string
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $page = $client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => '50',
            'email' => $email,
        ]);
        self::assertSees('Vendor created.', $page);

        return $this->match($page->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#');
    }

    /**
     * @return array{code: string, description: string}
     */
    private function item(FormClient $client, string $vendorId, string $description): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $created = $client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '10.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        return ['code' => $code, 'description' => $description];
    }

    private function itemId(FormClient $client, string $code): string
    {
        $client->post('/' . self::SLUG . '/pos/discard', []);
        $scan = $client->postJson('/' . self::SLUG . '/pos/scan', ['scan' => $code]);
        $ticket = $this->payload($scan->body);
        $lines = $ticket['ticket']['lines'] ?? null;
        self::assertIsArray($lines);
        $line = $lines[0] ?? null;
        self::assertIsArray($line);
        $id = $line['item_id'] ?? '';
        self::assertIsString($id);
        $client->post('/' . self::SLUG . '/pos/discard', []);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function dollars(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $found), $html);

        return $found[1];
    }
}
