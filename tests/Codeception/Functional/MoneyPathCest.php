<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use RuntimeException;

/**
 * Explicit money paths the safe form crawl refuses to POST.
 */
final class MoneyPathCest
{
    public function ownerPostsACashSaleAndSeesTheJournal(FunctionalTester $I): void
    {
        $I->wantTo('open a register, scan an item, take cash, and open the sale journal');
        $client = ProbeHarness::require()->client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(ProbeMall::SLUG);
        $slug = ProbeMall::SLUG;
        $this->openRegister($client, $slug);

        $vendorCode = 'v' . bin2hex(random_bytes(3));
        $vendor = $client->post('/' . $slug . '/vendors', [
            'code' => $vendorCode,
            'first_name' => 'Sale',
            'last_name' => $vendorCode,
        ]);
        $I->assertStringContainsString('Vendor created.', $vendor->body);
        if (preg_match('#/vendors/([0-9a-fA-F-]{36})#', $vendor->body, $vendorMatch) !== 1) {
            $I->fail('vendor id missing after create');
        }
        $vendorId = strtolower($vendorMatch[1]);

        $tag = 't' . bin2hex(random_bytes(3));
        $description = 'Surface coat ' . $tag;
        $created = $client->post('/' . $slug . '/inventory', [
            'code' => $tag,
            'description' => $description,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '12.00',
            'barcode' => 'BC-' . $tag,
            'intake_on' => '2026-09-01',
        ]);
        $I->assertStringContainsString('Item created.', $created->body);

        $client->post('/' . $slug . '/pos/discard', []);
        $cart = $client->post('/' . $slug . '/pos/scan', ['scan' => 'BC-' . $tag]);
        $I->assertStringContainsString($description, $cart->body);
        $paid = $client->post('/' . $slug . '/pos/pay', [
            'tender_cash' => '12.00',
            'cash_received' => '12.00',
        ], 120);
        $I->assertSame(1, preg_match('#/pos/receipt/([0-9a-f-]{36})#', $paid->body, $journal));
        $receipt = $client->get('/' . $slug . '/journals/' . $journal[1]);
        $I->assertStringContainsString('<p>Sale</p>', $receipt->body);
    }

    public function ownerRentChargeCreatesAJournal(FunctionalTester $I): void
    {
        $I->wantTo('charge booth rent and open the rent journal');
        $client = ProbeHarness::require()->client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(ProbeMall::SLUG);
        $slug = ProbeMall::SLUG;

        $boothCode = 'b' . bin2hex(random_bytes(3));
        $booths = $client->post('/' . $slug . '/booths', [
            'code' => $boothCode,
            'name' => $boothCode,
        ]);
        $I->assertStringContainsString($boothCode, $booths->body);
        $boothId = HtmlPage::taggedId($booths->body, 'tr', 'booth', $boothCode);
        $client->post('/' . $slug . '/booths/' . $boothId, [
            'code' => $boothCode,
            'name' => $boothCode,
            'amount' => '100.00',
        ]);

        $vendorCode = 'v' . bin2hex(random_bytes(3));
        $vendor = $client->post('/' . $slug . '/vendors', [
            'code' => $vendorCode,
            'first_name' => 'Rent',
            'last_name' => $vendorCode,
        ]);
        $I->assertStringContainsString('Vendor created.', $vendor->body);
        if (preg_match('#/vendors/([0-9a-fA-F-]{36})#', $vendor->body, $vendorMatch) !== 1) {
            $I->fail('vendor id missing after create');
        }
        $vendorId = strtolower($vendorMatch[1]);
        $client->post('/' . $slug . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
        ]);
        $charged = $client->post('/' . $slug . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => ProbeMall::POSTING_DATE,
            'month' => ProbeMall::MONTH,
        ], 120);
        $I->assertStringContainsString('Charged', $charged->body);
        $I->assertSame(1, preg_match(
            '#id="booth-' . preg_quote($boothId, '#') . '".*?/journals/([0-9a-fA-F-]{36})#s',
            $charged->body,
            $journalId
        ));
        $journal = $client->get('/' . $slug . '/journals/' . $journalId[1]);
        $I->assertStringContainsString('Rent charge', $journal->body);
    }

    public function ownerPayoutWithNothingOpenIsRefused(FunctionalTester $I): void
    {
        $I->wantTo('refuse a payout when the vendor has nothing open');
        $client = ProbeHarness::require()->client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $slug = ProbeMall::SLUG;
        $code = 'p' . bin2hex(random_bytes(3));
        $created = $client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Empty',
            'last_name' => $code,
        ]);
        $I->assertStringContainsString('Vendor created.', $created->body);
        if (preg_match('#/vendors/([0-9a-fA-F-]{36})#', $created->body, $match) !== 1) {
            $I->fail('vendor id missing after create');
        }
        $vendorId = strtolower($match[1]);
        $form = $client->get('/' . $slug . '/payout?vendor=' . rawurlencode($vendorId));
        $I->assertStringContainsString('Their share of sales', $form->body);
        $refused = $client->post('/' . $slug . '/payout', [
            'vendor_id' => $vendorId,
        ]);
        $I->assertStringContainsString(
            'a payout clears a positive open payable or open rent receivable',
            $refused->body
        );
    }

    private function openRegister(FormClient $client, string $slug): void
    {
        $page = $client->get('/' . $slug . '/registers');
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $active = $client->post($use[1], []);
            if (!str_contains($active->body, 'is the active register.') && !str_contains($active->body, 'Close register')) {
                throw new RuntimeException('register use did not activate a drawer');
            }

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
}
