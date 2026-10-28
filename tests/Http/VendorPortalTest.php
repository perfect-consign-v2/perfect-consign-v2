<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\PageResponse;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class VendorPortalTest extends WebTestCase
{
    private FormClient $owner;

    private const SLUG = ProbeMall::SLUG;

    public function testAVendorSeesOnlyTheirCheckAndItFoots(): void
    {
        $this->owner = self::client();
        $this->owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->owner->ensurePeriod(self::SLUG);
        $this->openRegister();
        $ada = $this->vendor('Ada', '0');
        $bea = $this->vendor('Bea', '0');
        $username = $this->signIn($ada['id'], $ada['code']);
        $other = $this->item($bea['id'], '8.00', 'Other');
        $twenty = $this->item($ada['id'], '20.00', 'Twenty');
        $ten = $this->item($ada['id'], '10.00', 'Ten');
        $six = $this->item($ada['id'], '6.00', 'Six');
        $this->clearCart();
        $this->payCard($other);
        $this->payCard($twenty);
        $returned = $this->payCard($ten);
        $itemId = $this->match($returned->body, '/name="item_id" value="([0-9a-fA-F-]{36})"/');
        $back = $this->owner->post($this->receiptAction($returned->body, 'return'), [
            'item_id' => $itemId,
            'return_tender' => 'cash',
        ]);
        self::assertSees('Item returned.', $back);
        $this->payDraw($six, $ada['id']);

        $vendor = self::client();
        $home = $vendor->signIn(self::SLUG, $username, 'vendor-pass');
        self::assertSees('Your check', $home);
        self::assertSees('Your share of sales', $home);
        self::assertSees('Ada ' . $ada['code'], $home);
        self::assertStringNotContainsString($other['description'], $home->body);
        self::assertSees('Bought at the store', $home);
        self::assertSees('Check', $home);
        self::assertSees('Sales', $home);
        self::assertSees('Inventory', $home);

        $sales = $vendor->get('/' . self::SLUG . '/vendor/sales');
        self::assertSees('Since your last check', $sales);
        self::assertSees($twenty['description'], $sales);
        self::assertSees($ten['description'], $sales);
        self::assertSees($six['description'], $sales);
        self::assertSees('-$10.00', $sales);
        self::assertStringNotContainsString($other['description'], $sales->body);
        $share = $this->cents($this->match($sales->body, '/id="sales-share">([^<]+)/'));
        $payable = $this->cents($this->match($home->body, '/id="check-share">([^<]+)/'));
        $drawn = 0;
        self::assertGreaterThan(0, preg_match_all('/class="draw-amount">([^<]+)/', $home->body, $draws));
        foreach ($draws[1] as $amount) {
            self::assertIsString($amount);
            $drawn += $this->cents($amount);
        }
        self::assertSame($payable, $share + $drawn);

        $foreign = $vendor->get('/' . self::SLUG . '/vendor?vendor=' . $bea['id']);
        self::assertSees('Ada ' . $ada['code'], $foreign);
        self::assertStringNotContainsString($other['description'], $foreign->body);
        $foreignSales = $vendor->get('/' . self::SLUG . '/vendor/sales?vendor=' . $bea['id']);
        self::assertSees($twenty['description'], $foreignSales);
        self::assertStringNotContainsString($other['description'], $foreignSales->body);

        $items = $vendor->get('/' . self::SLUG . '/vendor/inventory');
        self::assertSees('Your items', $items);
        self::assertSees('list-dt-filter', $items);
        self::assertSees('Qty 0', $items);
        self::assertMatchesRegularExpression('/' . preg_quote($ten['description'], '/') . '[\s\S]*?<td class="num">1<\/td>/', $items->body);
        self::assertMatchesRegularExpression('/' . preg_quote($twenty['description'], '/') . '[\s\S]*?<td class="num">0<\/td>/', $items->body);
        self::assertMatchesRegularExpression('/' . preg_quote($six['description'], '/') . '[\s\S]*?<td class="num">0<\/td>/', $items->body);
        self::assertStringNotContainsString($other['description'], $items->body);

        $missing = $vendor->get('/' . self::SLUG . '/vendor/checks/00000000-0000-7000-8000-000000000099');
        self::assertSame(404, $missing->status);
        $staff = $vendor->get('/' . self::SLUG . '/pos');
        self::assertSame(404, $staff->status);

        $clamped = $vendor->get('/' . self::SLUG . '/vendor/sales?range=custom&from=2026-06-02&to=2026-06-01');
        self::assertSees('Those dates', $clamped);
        self::assertSees('06-02-2026 to 06-02-2026', $clamped);
    }

    /**
     * @return array{id: string, code: string}
     */
    private function vendor(string $first, string $take): array
    {
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $this->owner->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => $first,
            'last_name' => $code,
            'store_take' => $take,
        ]);
        self::assertSees('Vendor created.', $created);

        return [
            'id' => $this->match($created->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#'),
            'code' => $code,
        ];
    }

    private function signIn(string $vendorId, string $code): string
    {
        $username = 'v' . bin2hex(random_bytes(4));
        $added = $this->owner->post('/' . self::SLUG . '/vendors/' . $vendorId . '/sign-in', [
            'username' => $username,
            'display_name' => 'Ada ' . $code,
            'password' => 'vendor-pass',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Vendor sign-in added.', $added);

        return $username;
    }

    /**
     * @return array{code: string, description: string}
     */
    private function item(string $vendorId, string $price, string $name): array
    {
        $code = 't' . bin2hex(random_bytes(3));
        $description = $name . ' ' . $code;
        $created = $this->owner->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        return ['code' => $code, 'description' => $description];
    }

    /**
     * @param array{code: string, description: string} $item
     */
    private function payCard(array $item): PageResponse
    {
        $this->owner->post('/' . self::SLUG . '/pos/new', []);
        $cart = $this->owner->post('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        self::assertSees($item['description'], $cart);
        $this->owner->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'card',
            'tender_amount' => $this->money($cart->body, '/class="register-due">([^<]+)/'),
        ]);
        $paid = $this->owner->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $paid);

        return $paid;
    }

    /**
     * @param array{code: string, description: string} $item
     */
    private function payDraw(array $item, string $vendorId): void
    {
        $this->owner->post('/' . self::SLUG . '/pos/new', []);
        $cart = $this->owner->post('/' . self::SLUG . '/pos/scan', ['scan' => $item['code']]);
        self::assertSees($item['description'], $cart);
        $lined = $this->owner->post('/' . self::SLUG . '/pos/line-discount', [
            'line_no' => $this->match($cart->body, '/name="line_no" value="(\d+)"/'),
            'discount_action' => 'apply',
            'discount_kind' => 'amount',
            'discount_value' => '1.00',
            'discount_reason' => 'damaged',
        ]);
        self::assertSees('Line discount saved.', $lined);
        $drawn = $this->owner->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'vendor_draw',
            'tender_amount' => '1.00',
            'vendor_id' => $vendorId,
        ]);
        self::assertSees('Tender saved.', $drawn);
        $this->owner->post('/' . self::SLUG . '/pos/tender', [
            'tender_role' => 'cash',
            'tender_amount' => $this->money($drawn->body, '/Still due <strong>([^<]+)<\/strong>/'),
        ]);
        $paid = $this->owner->post('/' . self::SLUG . '/pos/pay', []);
        self::assertSees('Sale posted.', $paid);
    }

    private function clearCart(): void
    {
        $pos = $this->owner->get('/' . self::SLUG . '/pos');
        if (!str_contains($pos->body, 'data-open-modal="clear-cart"')) {
            return;
        }
        $discarded = $this->owner->post('/' . self::SLUG . '/pos/discard', []);
        self::assertSees('Ticket discarded.', $discarded);
    }

    private function openRegister(): void
    {
        $page = $this->owner->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $active = $this->owner->post($use[1], []);
            self::assertSees('is the active register.', $active);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $counts = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $counts['count_' . $key] = '0';
        }
        $opened = $this->owner->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$counts,
        ]);
        self::assertSees('Close register', $opened);
    }

    private function receiptAction(string $html, string $action): string
    {
        return '/' . self::SLUG . '/pos/receipt/' . $this->match($html, '#/pos/receipt/([0-9a-fA-F-]{36})/' . $action . '#') . '/' . $action;
    }

    private function money(string $html, string $pattern): string
    {
        $shown = $this->match($html, $pattern);
        self::assertSame(1, preg_match('/\$([0-9]+\.[0-9]{2})/', $shown, $money));

        return $money[1];
    }

    private function cents(string $text): int
    {
        self::assertSame(1, preg_match('/(-?)\$([0-9]+)\.([0-9]{2})/', $text, $parts));
        $value = ((int) $parts[2]) * 100 + (int) $parts[3];

        return $parts[1] === '-' ? -$value : $value;
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
