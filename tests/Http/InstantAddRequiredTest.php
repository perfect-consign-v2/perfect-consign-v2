<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * Instant Add needs the seller, description, price, and quantity. A blank field, or one of
 * only spaces, is refused on the form and nothing reaches the ticket.
 */
final class InstantAddRequiredTest extends WebTestCase
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

    public function testTheFormMarksAllFourFieldsRequiredAndStartsQuantityAtOne(): void
    {
        $pos = $this->client->get('/' . ProbeMall::SLUG . '/pos');
        foreach (['instant_vendor', 'instant_description', 'instant_price', 'instant_quantity'] as $id) {
            self::assertMatchesRegularExpression('/<input[^>]*\sid="' . $id . '"[^>]*\srequired/', $pos->body, $id . ' is not required');
        }
        self::assertMatchesRegularExpression('/<input[^>]*\sid="instant_quantity"[^>]*\svalue="1"/', $pos->body);
        self::assertStringNotContainsString('MISSING', $pos->body);
    }

    public function testEachBlankOrSpacedFieldIsRefusedAndNothingReachesTheTicket(): void
    {
        $slug = ProbeMall::SLUG;
        $vendor = $this->vendor();
        $this->client->post('/' . $slug . '/pos/discard', []);
        $cases = [
            'no seller' => [['vendor_id' => '', 'vendor_label' => ''], ['Choose a vendor.', 'Choose a consignor.']],
            'spaced seller' => [['vendor_id' => '   ', 'vendor_label' => '   '], ['Choose a vendor.', 'Choose a consignor.']],
            'no description' => [['description' => ''], ['Enter a description.']],
            'spaced description' => [['description' => "  \t "], ['Enter a description.']],
            'no price' => [['price' => ''], ['Enter a price.']],
            'spaced price' => [['price' => '   '], ['Enter a price.']],
            'no quantity' => [['quantity' => ''], ['Enter a quantity.']],
            'spaced quantity' => [['quantity' => '   '], ['Enter a quantity.']],
            'zero quantity' => [['quantity' => '0'], ['Enter a quantity of at least 1.']],
            'word quantity' => [['quantity' => 'two'], ['Enter a whole quantity.']],
        ];
        foreach ($cases as $label => [$change, $messages]) {
            $tag = 'Req ' . bin2hex(random_bytes(3));
            $refused = $this->client->post('/' . $slug . '/pos/instant', array_merge([
                'vendor_id' => $vendor['id'],
                'vendor_label' => $vendor['code'],
                'description' => $tag,
                'price' => '12.00',
                'quantity' => '1',
                'instant_action' => 'save_and_close',
            ], $change));
            self::assertTrue($this->saysOneOf($refused->body, $messages), $label . ' was not refused with ' . implode(' / ', $messages));
            self::assertMatchesRegularExpression('/id="instant-add"(?![^>]*\shidden)/', $refused->body, $label . ' closed the form');
            $pos = $this->client->get('/' . $slug . '/pos');
            if (($change['description'] ?? null) === null) {
                self::assertStringNotContainsString($tag, $pos->body, $label . ' put a line on the ticket');
            }
            self::assertStringNotContainsString('register-cart-line', $pos->body, $label . ' put a line on the ticket');
        }
    }

    public function testAFilledFormTrimsTheFieldsAndRingsTheQuantity(): void
    {
        $slug = ProbeMall::SLUG;
        $vendor = $this->vendor();
        $this->client->post('/' . $slug . '/pos/discard', []);
        $tag = 'Lamp ' . bin2hex(random_bytes(3));
        $added = $this->client->post('/' . $slug . '/pos/instant', [
            'vendor_id' => ' ' . $vendor['id'] . ' ',
            'vendor_label' => $vendor['code'],
            'description' => '   ' . $tag . '   ',
            'price' => ' 12.00 ',
            'quantity' => ' 3 ',
            'instant_action' => 'save_and_close',
        ]);
        self::assertSees('Item added to cart.', $added);
        self::assertSees($tag, $added);
        self::assertStringNotContainsString('   ' . $tag, $added->body);
        self::assertMatchesRegularExpression('/register-qty-input"[^>]*\svalue="3"/', $added->body);
        $this->client->post('/' . $slug . '/pos/discard', []);
    }

    public function testTheMissingLineAddressIsGone(): void
    {
        $gone = $this->client->post('/' . ProbeMall::SLUG . '/pos/draft/1/remove', []);
        self::assertSame(404, $gone->status);
    }

    /**
     * @return array{id: string, code: string}
     */
    private function vendor(): array
    {
        $slug = ProbeMall::SLUG;
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Req',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $created);

        return ['id' => HtmlPage::pathId($created->body, $slug . '/vendors'), 'code' => $code];
    }

    /**
     * @param list<string> $messages
     */
    private function saysOneOf(string $body, array $messages): bool
    {
        foreach ($messages as $message) {
            if (str_contains($body, htmlspecialchars($message, ENT_QUOTES))) {
                return true;
            }
        }

        return false;
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
