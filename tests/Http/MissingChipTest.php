<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class MissingChipTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
        $this->openRegister();
    }

    public function testIncompleteInstantAddShowsMissingChipAndBlocksPay(): void
    {
        $slug = ProbeMall::SLUG;
        $this->client->post('/' . $slug . '/pos/discard', []);
        $drafted = $this->client->post('/' . $slug . '/pos/instant', [
            'vendor_id' => '',
            'vendor_label' => '',
            'description' => 'Half done',
            'price' => '',
            'instant_action' => 'save_and_close',
        ]);
        self::assertSees('Incomplete line on the ticket.', $drafted);
        self::assertSees('MISSING', $drafted);
        self::assertSees('vendor', $drafted);
        self::assertSees('price', $drafted);
        self::assertSees('Half done', $drafted);

        $code = 'v' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Miss',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $created);
        $vendorId = HtmlPage::pathId($created->body, $slug . '/vendors');

        $pos = $this->client->get('/' . $slug . '/pos');
        self::assertSees('Finish sale', $pos);
        self::assertMatchesRegularExpression('/register-complete"[^>]*\sdisabled/', $pos->body);

        $paid = $this->client->post('/' . $slug . '/pos/pay', [
            'tender_cash' => '10.00',
            'pay_with' => 'cash',
        ]);
        self::assertSees('Complete Instant Add lines before pay.', $paid);

        $completed = $this->client->post('/' . $slug . '/pos/instant', [
            'draft_no' => '1',
            'vendor_id' => $vendorId,
            'vendor_label' => $code,
            'description' => 'Half done',
            'price' => '10.00',
            'instant_action' => 'save_and_close',
        ]);
        self::assertSees('Item added to cart.', $completed);
        self::assertStringNotContainsString('register-draft', $completed->body);
        self::assertStringNotContainsString('badge-danger">MISSING', $completed->body);
    }

    private function openRegister(): void
    {
        $slug = ProbeMall::SLUG;
        $page = $this->client->get('/' . $slug . '/registers');
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        self::assertSame(1, preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $open));
        $opened = $this->client->post($open[1], [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            'penny' => '0',
            'nickel' => '0',
            'dime' => '0',
            'quarter' => '0',
            'half' => '0',
            'dollar_coin' => '0',
            'one' => '0',
            'two' => '0',
            'five' => '0',
            'ten' => '0',
            'twenty' => '0',
            'fifty' => '0',
            'hundred' => '0',
        ]);
        self::assertSees('Close register', $opened);
    }
}
