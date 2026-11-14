<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class BookMoneyCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(self::SLUG);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
        $this->money('both');
    }

    protected function tearDown(): void
    {
        $this->operatingMode('both');
        $this->money('both');
        parent::tearDown();
    }

    public function testAMissedSaleShowsTheShareAndPostsOnce(): void
    {
        $vendorId = $this->vendor();
        $item = $this->item($vendorId);
        $form = $this->client->get('/' . self::SLUG . '/missed-sale');
        self::assertSees('Missed sale', $form);
        self::assertSees('The vendor is paid their usual share', $form);
        self::assertSees('Show the vendor share', $form);
        self::assertSees('The sale was never rung', $form);
        self::assertStringNotContainsString('value="' . $vendorId . '" selected', $form->body);

        $badPrice = $this->client->post('/' . self::SLUG . '/missed-sale', [
            'vendor_id' => $vendorId,
            'item_code' => '',
            'description' => 'Lamp',
            'price' => 'nope',
            'memo' => 'walked out',
            'posting_date' => ProbeMall::POSTING_DATE,
            'form_token' => 'nope',
        ]);
        self::assertSees('enter an amount in dollars and cents', $badPrice);

        $missingVendor = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            '00000000-0000-7000-8000-000000000099',
            '',
            'Lamp',
            '10.00',
            'walked out',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('a missed sale needs a vendor', $missingVendor);

        $missingItem = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            'missing-piece',
            'Lamp',
            '10.00',
            'walked out',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('a missed sale needs an item', $missingItem);

        $preview = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            '',
            'Lamp',
            '10.00',
            'walked out',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('Vendor share $10.00', $preview);
        self::assertSees('value="' . $vendorId . '" selected', $preview);
        self::assertSees('value="walked out"', $preview);
        self::assertSees('value="' . ProbeMall::POSTING_DATE . '"', $preview);
        $token = $this->token($preview->body);
        $nameless = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            '',
            '   ',
            '10.00',
            'walked out',
            $token,
            true,
        ));
        self::assertSees('a missed sale names the piece', $nameless);
        $silent = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            '',
            'Lamp',
            '10.00',
            '   ',
            $token,
            true,
        ));
        self::assertSees('a missed sale names the piece', $silent);

        $posted = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            '',
            'Lamp',
            '10.00',
            'walked out',
            $token,
            true,
        ));
        self::assertSees('Missed sale posted.', $posted);
        self::assertSees('missed-sale:' . $token, $posted);
        self::assertSees('missed_sale', $posted);
        $again = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            '',
            'Lamp',
            '10.00',
            'walked out',
            $token,
            true,
        ));
        self::assertSees('missed-sale:' . $token, $again);

        $tagged = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            $item,
            'Tagged lamp',
            '8.00',
            'the tag was on it',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('Vendor share $8.00', $tagged);
        $taggedToken = $this->token($tagged->body);
        $withItem = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            $item,
            'Tagged lamp',
            '8.00',
            'the tag was on it',
            $taggedToken,
            true,
        ));
        self::assertSees('Missed sale posted.', $withItem);
        self::assertSees('missed-sale:' . $taggedToken, $withItem);
    }

    public function testAVendorMemoShowsTheBalanceAndPostsBothWays(): void
    {
        $vendorId = $this->vendor();
        $form = $this->client->get('/' . self::SLUG . '/vendor-memo');
        self::assertSees('Vendor memo', $form);
        self::assertSees('what the mall owes this vendor', $form);
        self::assertSees('Show the open payable', $form);
        self::assertSees('value="credit" selected', $form);

        $badAmount = $this->client->post('/' . self::SLUG . '/vendor-memo', [
            'vendor_id' => $vendorId,
            'direction' => 'credit',
            'amount' => 'nope',
            'reason' => 'rent fix',
            'posting_date' => ProbeMall::POSTING_DATE,
            'form_token' => 'nope',
        ]);
        self::assertSees('enter an amount in dollars and cents', $badAmount);

        $missing = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            '00000000-0000-7000-8000-000000000099',
            'credit',
            '5.00',
            'rent fix',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('a vendor memo needs a vendor', $missing);

        $preview = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'debit',
            '4.00',
            'rent fix',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('Open payable $0.00', $preview);
        self::assertSees('value="debit" selected', $preview);
        self::assertSees('value="' . $vendorId . '" selected', $preview);
        self::assertSees('value="' . ProbeMall::POSTING_DATE . '"', $preview);
        self::assertSees('Post vendor memo', $preview);
        $token = $this->token($preview->body);
        $nameless = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'credit',
            '4.00',
            '   ',
            $token,
            true,
        ));
        self::assertSees('a vendor memo needs a reason', $nameless);
        $sideways = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'sideways',
            '4.00',
            'rent fix',
            $token,
            true,
        ));
        self::assertSees('a vendor memo credits or debits the vendor', $sideways);

        $credit = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'credit',
            '4.00',
            'rent fix',
            $token,
            true,
        ));
        self::assertSees('Vendor memo posted.', $credit);
        self::assertSees('vendor-memo:' . $token, $credit);
        self::assertSees('vendor_memo', $credit);
        $again = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'credit',
            '4.00',
            'rent fix',
            $token,
            true,
        ));
        self::assertSees('vendor-memo:' . $token, $again);

        $fresh = $this->client->get('/' . self::SLUG . '/vendor-memo');
        $debitToken = $this->token($fresh->body);
        $debit = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'debit',
            '1.50',
            'charge back',
            $debitToken,
            true,
        ));
        self::assertSees('Vendor memo posted.', $debit);
        self::assertSees('vendor-memo:' . $debitToken, $debit);
    }

    public function testAStoreUsePostsTheAgreedPrice(): void
    {
        $vendorId = $this->vendor();
        $item = $this->item($vendorId);
        $form = $this->client->get('/' . self::SLUG . '/store-use');
        self::assertSees('Buy for the store', $form);
        self::assertSees('buying this from the vendor', $form);
        self::assertSees('Bought for the business, not for resale', $form);
        $token = $this->token($form->body);

        $badAmount = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            $item,
            'Lamp',
            'nope',
            'for the office',
            $token,
        ));
        self::assertSees('enter an amount in dollars and cents', $badAmount);
        $nameless = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            $item,
            '   ',
            '12.50',
            'for the office',
            $token,
        ));
        self::assertSees('a store use names the piece', $nameless);
        $silent = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            $item,
            'Lamp',
            '12.50',
            '   ',
            $token,
        ));
        self::assertSees('a store use names the piece', $silent);
        $expired = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            $item,
            'Lamp',
            '12.50',
            'for the office',
            'nope',
        ));
        self::assertSees('this form has expired', $expired);
        $blankItem = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            '   ',
            'Lamp',
            '12.50',
            'for the office',
            $token,
        ));
        self::assertSees('a store use needs an item', $blankItem);
        $missingItem = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            'missing-piece',
            'Lamp',
            '12.50',
            'for the office',
            $token,
        ));
        self::assertSees('a store use needs an item', $missingItem);

        $posted = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            $item,
            'Lamp',
            '12.50',
            'for the office',
            $token,
        ));
        self::assertSees('Store use posted.', $posted);
        self::assertSees('store_use', $posted);
        $duplicate = $this->client->post('/' . self::SLUG . '/store-use', $this->useFields(
            $vendorId,
            $item,
            'Lamp',
            '12.50',
            'for the office',
            $token,
        ));
        self::assertSees('That could not be saved.', $duplicate);
        self::assertSees('value="' . $vendorId . '" selected', $duplicate);
        self::assertSees('value="Lamp"', $duplicate);
        self::assertSees('value="' . ProbeMall::POSTING_DATE . '"', $duplicate);
    }

    public function testABouncedCheckPostsOnce(): void
    {
        $form = $this->client->get('/' . self::SLUG . '/checks/nsf');
        self::assertSees('Bounced check', $form);
        self::assertSees('This credits checks and debits returned checks.', $form);
        self::assertStringNotContainsString('a source reference is required', $form->body);

        $blank = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'source_reference' => '',
            'amount' => '12.34',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('a source reference is required', $blank);
        $badAmount = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'source_reference' => 'chk-bad',
            'amount' => 'nope',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('enter an amount in dollars and cents', $badAmount);
        $zero = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'source_reference' => 'chk-zero',
            'amount' => '0.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('the amount must be greater than zero', $zero);

        $reference = 'chk' . bin2hex(random_bytes(4));
        $posted = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'source_reference' => $reference,
            'amount' => '12.34',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('Bounced check posted.', $posted);
        self::assertSees('check_return', $posted);
        self::assertSees('check-return:' . $reference, $posted);
        $again = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'source_reference' => $reference,
            'amount' => '12.34',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('That could not be saved.', $again);
    }

    public function testConsignmentCopyOnTheBookForms(): void
    {
        $vendorId = $this->vendor();
        $this->operatingMode('consignor');
        $missed = $this->client->get('/' . self::SLUG . '/missed-sale');
        self::assertSees('The consignor is paid their usual share', $missed);
        self::assertSees('Show the consignor share', $missed);
        $preview = $this->client->post('/' . self::SLUG . '/missed-sale', $this->saleFields(
            $vendorId,
            '',
            'Lamp',
            '10.00',
            'walked out',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('Consignor share $10.00', $preview);

        $memo = $this->client->get('/' . self::SLUG . '/vendor-memo');
        self::assertSees('Consignor memo', $memo);
        self::assertSees('owes this consignor', $memo);
        $owed = $this->client->post('/' . self::SLUG . '/vendor-memo', $this->memoFields(
            $vendorId,
            'credit',
            '2.00',
            'bonus',
            bin2hex(random_bytes(16)),
            false,
        ));
        self::assertSees('Post consignor memo', $owed);

        $use = $this->client->get('/' . self::SLUG . '/store-use');
        self::assertSees('buying this from the consignor', $use);
    }

    private function operatingMode(string $mode): void
    {
        $pdo = $this->admin();
        $schemaStmt = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $schemaStmt->execute([self::SLUG]);
        $schema = $schemaStmt->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('the probe schema is missing');
        }
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $update = $pdo->prepare('UPDATE mall SET operating_mode = ?');
        $update->execute([$mode]);
    }

    private function admin(): PDO
    {
        $env = static function (string $name, string $fallback): string {
            $value = getenv($name);

            return is_string($value) && $value !== '' ? $value : $fallback;
        };

        return new PDO(
            'pgsql:host=' . $env('STALL_DB_HOST', '127.0.0.1')
                . ';port=' . $env('STALL_DB_PORT', '5436')
                . ';dbname=' . $env('STALL_DB_NAME', 'consign'),
            $env('STALL_DB_USER', 'stall'),
            $env('STALL_DB_PASSWORD', 'stall'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function money(string $mode): void
    {
        $saved = $this->client->post('/' . self::SLUG . '/settings/money', [
            'settings_part' => 'money',
            'default_store_take' => '0',
            'tax_rate' => '0',
            'operating_mode' => $mode,
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
            'late_fee_grace_days' => '0',
            'cash_round_minor' => '0',
        ]);
        self::assertSees('Money saved.', $saved);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $page = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $page);

        return $this->match($page->body, '/id="vendor-([0-9a-fA-F-]{36})"/');
    }

    private function item(string $vendorId): string
    {
        $code = 't' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => 'Item ' . $code,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '8.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private function saleFields(
        string $vendorId,
        string $itemCode,
        string $description,
        string $price,
        string $memo,
        string $token,
        bool $post,
    ): array {
        $fields = [
            'vendor_id' => $vendorId,
            'item_code' => $itemCode,
            'description' => $description,
            'price' => $price,
            'memo' => $memo,
            'posting_date' => ProbeMall::POSTING_DATE,
            'form_token' => $token,
        ];
        if ($post) {
            $fields['post'] = '1';
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function memoFields(
        string $vendorId,
        string $direction,
        string $amount,
        string $reason,
        string $token,
        bool $post,
    ): array {
        $fields = [
            'vendor_id' => $vendorId,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'posting_date' => ProbeMall::POSTING_DATE,
            'form_token' => $token,
        ];
        if ($post) {
            $fields['post'] = '1';
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function useFields(
        string $vendorId,
        string $itemCode,
        string $description,
        string $amount,
        string $memo,
        string $token,
    ): array {
        return [
            'vendor_id' => $vendorId,
            'item_code' => $itemCode,
            'description' => $description,
            'amount' => $amount,
            'memo' => $memo,
            'posting_date' => ProbeMall::POSTING_DATE,
            'form_token' => $token,
        ];
    }

    private function token(string $html): string
    {
        return $this->match($html, '/name="form_token" value="([0-9a-f]{32})"/');
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
