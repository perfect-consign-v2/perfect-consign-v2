<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Pay one vendor, then require the check face and the sales and rent sheet.
 * A number form, or a check face that prints the company name or the check number, is a failure.
 */
final class SingleVendorCheckPrint
{
    /**
     * @return array{runId: string, description: string}
     */
    public static function payOneVendor(FormClient $client, string $slug): array
    {
        $client->ensurePeriod($slug);
        self::openRegister($client, $slug);
        $code = 'p' . bin2hex(random_bytes(3));
        $created = $client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Pay',
            'last_name' => $code,
            'store_take' => '50',
            'payout_mode' => 'pay',
        ]);
        Assert::assertStringContainsString('Vendor created.', $created->body);
        $vendorId = self::match($created->body, '#/vendors/([0-9a-fA-F-]{36})#');
        $tag = 'k' . bin2hex(random_bytes(3));
        $description = 'Payout ' . $tag;
        $item = $client->post('/' . $slug . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '10.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        Assert::assertStringContainsString('Item created.', $item->body);
        $client->post('/' . $slug . '/pos/new', []);
        $client->post('/' . $slug . '/pos/discard', []);
        $cart = $client->post('/' . $slug . '/pos/scan', ['scan' => $tag]);
        Assert::assertStringContainsString($tag, $cart->body);
        $due = self::match($cart->body, '/class="register-due">\$([0-9.]+)</');
        $paid = $client->post('/' . $slug . '/pos/pay', [
            'tender_cash' => $due,
            'cash_received' => $due,
        ], 120);
        Assert::assertStringContainsString('Sale posted.', $paid->body);
        $client->post('/' . $slug . '/pos/new', []);
        $posted = $client->post('/' . $slug . '/payout', ['vendor_id' => $vendorId], 120);
        Assert::assertStringContainsString('Payout posted.', $posted->body, HtmlPage::error($posted->body));
        $form = $client->get('/' . $slug . '/payout?vendor=' . $vendorId);
        $runId = self::match($form->body, '/name="run_id" value="([0-9a-fA-F-]{36})"/');

        return ['runId' => $runId, 'description' => $description];
    }

    public static function assertFace(FormClient $client, string $slug, string $runId): void
    {
        $checks = $client->get('/' . $slug . '/payouts/checks?run=' . $runId);
        Assert::assertSame(200, $checks->status);
        Assert::assertStringContainsString('Pay to the order of', $checks->body, HtmlPage::error($checks->body));
        Assert::assertStringContainsString('Print sales and rent', $checks->body);
        Assert::assertStringContainsString('/' . $slug . '/payouts/report?run=' . $runId, $checks->body);
        Assert::assertStringNotContainsString('name="starting_check"', $checks->body);
        Assert::assertStringNotContainsString('First check number', $checks->body);
        Assert::assertStringNotContainsString('class="check-mall"', $checks->body);
        Assert::assertStringNotContainsString('class="check-number"', $checks->body);
    }

    public static function assertSheet(FormClient $client, string $slug, string $runId, string $description): void
    {
        $sheet = $client->get('/' . $slug . '/payouts/report?run=' . $runId);
        Assert::assertSame(200, $sheet->status);
        Assert::assertStringContainsString('what they sold and the check', $sheet->body, HtmlPage::error($sheet->body));
        Assert::assertStringContainsString(
            $description,
            $sheet->body,
            'the sales and rent sheet omitted the sale'
        );
        Assert::assertStringContainsString('Store fee', $sheet->body);
    }

    private static function openRegister(FormClient $client, string $slug): void
    {
        $page = $client->get('/' . $slug . '/registers');
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $match) !== 1) {
            throw new RuntimeException('no open-register action on /registers');
        }
        $fields = [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
        ];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        $opened = $client->post($match[1], $fields);
        if (!str_contains($opened->body, 'Close register')) {
            throw new RuntimeException('register open did not leave a close action');
        }
    }

    private static function match(string $html, string $pattern): string
    {
        if (preg_match($pattern, $html, $match) !== 1 || !isset($match[1]) || !is_string($match[1])) {
            Assert::fail('missing ' . $pattern);
        }

        return $match[1];
    }
}
