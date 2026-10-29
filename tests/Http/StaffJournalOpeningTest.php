<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
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
            'display_name' => 'Cash',
            'username' => $username,
            'password' => 'short',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('at least 8 characters', $short);
        $added = $this->client->post('/' . self::SLUG . '/staff', [
            'display_name' => 'Cash',
            'username' => $username,
            'password' => 'cashier1',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Cashier added.', $added);
        $again = $this->client->post('/' . self::SLUG . '/staff', [
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

    public function testABrandInUseCannotBeDeleted(): void
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
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', $code);
        $tag = 't' . bin2hex(random_bytes(3));
        $item = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $tag,
            'description' => 'Held ' . $tag,
            'vendor_id' => $vendorId,
            'brand_id' => $brandId,
            'price' => '4.00',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $item);
        $blocked = $this->client->post('/' . self::SLUG . '/brands/' . $brandId . '/delete', []);
        self::assertSees('That brand is in use.', $blocked);
        $this->client->get('/' . self::SLUG . '/brands?q=nomatch&rename=' . $brandId);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $found), $pattern);

        return $found[1];
    }
}
