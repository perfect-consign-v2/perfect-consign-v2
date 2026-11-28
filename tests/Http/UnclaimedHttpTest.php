<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * Money the store cannot pay a vendor, through the real screens: after the
 * dormancy days the vendor page offers the move, the vendor can then be made
 * inactive, the owner pays them when they come back and records the rest as
 * sent to the state, and Reports → Unclaimed money lists it.
 */
final class UnclaimedHttpTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = self::client();
        self::assertSees("Today's sales", $this->owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD));
    }

    public function testTheOwnerMovesPaysAndSendsUnclaimedMoney(): void
    {
        $tag = bin2hex(random_bytes(3));
        $created = $this->owner->post('/' . self::SLUG . '/vendors', ['code' => 'u' . $tag, 'first_name' => 'Ula', 'last_name' => $tag, 'store_take' => '0']);
        self::assertSees('Vendor created.', $created);
        self::assertSame(1, preg_match('#/vendors/([0-9a-fA-F-]{36})/sign-in#', $created->body, $found));
        $vendor = strtolower($found[1]);
        $record = '/' . self::SLUG . '/vendors/' . $vendor;

        $code = 'u' . $tag;
        self::assertSees('Item created.', $this->owner->post('/' . self::SLUG . '/inventory', [
            'code' => $code, 'description' => 'Unclaimed ' . $code, 'vendor_id' => $vendor, 'brand_id' => '',
            'price' => '50.00', 'barcode' => '', 'intake_on' => '2026-09-01',
        ]));
        self::useOpenRegister($this->owner, self::SLUG);
        $this->owner->post('/' . self::SLUG . '/pos/new', []);
        $this->owner->post('/' . self::SLUG . '/pos/discard', []);
        $cart = $this->owner->post('/' . self::SLUG . '/pos/scan', ['scan' => $code]);
        self::assertSame(1, preg_match('/class="register-due">\$([0-9.]+)</', $cart->body, $due));
        self::assertSees('Sale posted.', $this->owner->post('/' . self::SLUG . '/pos/pay', ['tender_cash' => $due[1], 'cash_received' => $due[1]]));
        $this->owner->post('/' . self::SLUG . '/pos/new', []);

        self::assertStringNotContainsString('/unclaimed"', $this->owner->get($record)->body);
        self::assertSees('Saved.', $this->owner->post('/' . self::SLUG . '/settings/bad-debts', ['write_off_wait_days' => '90', 'unclaimed_dormancy_days' => '0']));
        try {
            $page = $this->owner->get($record);
            self::assertSees('Money the store cannot pay', $page);
            self::assertSees('Move $50.00 to unclaimed money', $page);
            self::assertSees('Say why the store cannot pay them.', $this->owner->post($record . '/unclaimed', ['reason' => ' ']));
            $moved = $this->owner->post($record . '/unclaimed', ['reason' => 'Checks never cashed, no reply']);
            self::assertSees('Moved to unclaimed money. They can now be made inactive.', $moved);
            self::assertSees('The store holds $50.00 of unclaimed money for Ula.', $moved);
            self::assertSees('Vendor is inactive.', $this->owner->post($record . '/standing', ['standing' => 'inactive']));

            self::assertSees('Enter the check number.', $this->owner->post($record . '/unclaimed/pay', ['amount' => '20.00', 'pay_with' => 'check']));
            self::assertSees('Paid from unclaimed money.', $this->owner->post($record . '/unclaimed/pay', ['amount' => '20.00', 'pay_with' => 'check', 'check_number' => '1042']));
            self::assertSees('Paid from unclaimed money.', $this->owner->post($record . '/unclaimed/pay', ['amount' => '10.00', 'pay_with' => 'cash']));
            self::assertSees('The store holds $20.00 for them.', $this->owner->post($record . '/unclaimed/remit', ['amount' => '25.00', 'state_reference' => 'CA-1']));
            $sent = $this->owner->post($record . '/unclaimed/remit', ['amount' => '20.00', 'state_reference' => 'CA-2027-118']);
            self::assertSees('Recorded as sent to the state.', $sent);
            self::assertStringNotContainsString('of unclaimed money for Ula', $sent->body);

            $report = $this->owner->get('/' . self::SLUG . '/reports/unclaimed');
            self::assertSees('Ula ' . $tag, $report);
            self::assertSees('CA-2027-118', $report);
            $csv = $this->owner->get('/' . self::SLUG . '/reports/unclaimed.csv');
            self::assertStringContainsString('CA-2027-118', $csv->body);
        } finally {
            $this->owner->post('/' . self::SLUG . '/settings/bad-debts', ['write_off_wait_days' => '90', 'unclaimed_dormancy_days' => '1095']);
        }
    }
}
