<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * Writing off what a vendor owes through the real screens (D4-D9): the vendor
 * page shows what they owe and the Write off button, the write-off page asks
 * why, the vendor record keeps the line and warns above Make active, the owner
 * takes money back when they pay, and Reports → Bad debts lists it.
 */
final class BadDebtHttpTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = self::client();
        self::assertSees("Today's sales", $this->owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD));
    }

    public function testTheOwnerWritesOffOldRentAndTakesSomeBack(): void
    {
        $settings = $this->owner->get('/' . self::SLUG . '/settings/bad-debts');
        self::assertSees('Days past due before a debt can be written off', $settings);
        self::assertSees('Enter the days as a whole number from 0 to 3650.', $this->owner->post('/' . self::SLUG . '/settings/bad-debts', ['write_off_wait_days' => 'soon']));
        self::assertSees('Saved.', $this->owner->post('/' . self::SLUG . '/settings/bad-debts', ['write_off_wait_days' => '90']));

        $tag = bin2hex(random_bytes(3));
        $created = $this->owner->post('/' . self::SLUG . '/vendors', ['code' => 'w' . $tag, 'first_name' => 'Wren', 'last_name' => $tag, 'store_take' => '40']);
        self::assertSees('Vendor created.', $created);
        self::assertSame(1, preg_match('#/vendors/([0-9a-fA-F-]{36})/sign-in#', $created->body, $found));
        $vendor = strtolower($found[1]);
        $name = 'Wren ' . $tag;
        $code = 'w' . $tag;
        self::assertSees('Booth created.', $this->owner->post('/' . self::SLUG . '/booths', ['code' => $code, 'name' => $code, 'amount' => '70.00']));
        $booths = $this->owner->get('/' . self::SLUG . '/booths');
        self::assertSame(1, preg_match('#<tr[^>]*id="booth-([0-9a-f-]{36})"[^>]*>(?:(?!</tr>).)*' . preg_quote($code, '#') . '#s', $booths->body, $boothFound));
        ProbeMall::plantStay($boothFound[1], $vendor, '2026-06-01', '2026-07-31', 7000);
        $july = $this->owner->get('/' . self::SLUG . '/rent?month=2026-07');
        self::assertSees('Rent charged.', $this->owner->post('/' . self::SLUG . '/rent/charge', self::chargeButton($july->body, $code)));

        $page = $this->owner->get('/' . self::SLUG . '/vendors/' . $vendor);
        self::assertSees('They owe us', $page);
        self::assertSees('Rent for booth ' . $code, $page);
        self::assertSees('/vendors/' . $vendor . '/write-off', $page);

        $form = $this->owner->get('/' . self::SLUG . '/vendors/' . $vendor . '/write-off');
        self::assertSees('Wren owes $70.00. Writing it off records it as a loss. If Wren pays later, you can still take the money.', $form);
        self::assertSees('Say why it is written off.', $this->owner->post('/' . self::SLUG . '/vendors/' . $vendor . '/write-off', ['reason' => ' ']));
        $written = $this->owner->post('/' . self::SLUG . '/vendors/' . $vendor . '/write-off', ['reason' => 'Moved out of state, no forwarding address']);
        self::assertSees('$70.00 written off. Wren can now be made inactive.', $written);
        self::assertSees('Written off $70.00 on ', $written);
        self::assertSees(': Moved out of state, no forwarding address.', $written);

        self::assertSees('Vendor is inactive.', $this->owner->post('/' . self::SLUG . '/vendors/' . $vendor . '/standing', ['standing' => 'inactive']));
        $edit = $this->owner->get('/' . self::SLUG . '/vendors/' . $vendor . '/edit');
        self::assertSees('Making them active again is allowed.', $edit);

        $report = $this->owner->get('/' . self::SLUG . '/reports/bad-debts');
        self::assertSees('Bad debts', $report);
        self::assertSees($name, $report);
        self::assertSees('Moved out of state, no forwarding address', $report);
        $csv = $this->owner->get('/' . self::SLUG . '/reports/bad-debts.csv');
        self::assertStringContainsString('Moved out of state, no forwarding address', $csv->body);
        self::assertStringContainsString('70.00', $csv->body);
        self::assertSees('Written off', $this->owner->get('/' . self::SLUG . '/reports/rent-payments?' . http_build_query(['vendor' => $vendor, 'paid_by' => 'written_off'])));

        $vendorPage = $this->owner->get('/' . self::SLUG . '/vendors/' . $vendor);
        self::assertSame(1, preg_match('#/write-offs/([0-9a-f-]{36})/recover#', $vendorPage->body, $writeOff));
        $journal = $this->owner->get('/' . self::SLUG . '/journals/' . $writeOff[1]);
        self::assertStringNotContainsString('/journals/' . $writeOff[1] . '/void', $journal->body);
        $tooMuch = $this->owner->post('/' . self::SLUG . '/vendors/' . $vendor . '/write-offs/' . $writeOff[1] . '/recover', ['amount' => '80.00']);
        self::assertSees('You can take back up to $70.00 for this write-off.', $tooMuch);
        $back = $this->owner->post('/' . self::SLUG . '/vendors/' . $vendor . '/write-offs/' . $writeOff[1] . '/recover', ['amount' => '25.00']);
        self::assertSees('$25.00 taken back. Take their payment on the rent page or at the next payout.', $back);
        self::assertSees('Taken back so far: $25.00.', $back);
    }
}
