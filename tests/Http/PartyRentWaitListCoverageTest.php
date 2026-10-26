<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PartyRentWaitListCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const MISSING = '00000000-0000-7000-8000-000000000099';

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testMissingPartyAndWaitListIdsAreNotFound(): void
    {
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/booths/' . self::MISSING . '/edit')->status);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/vendors/' . self::MISSING)->status);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/vendors/' . self::MISSING . '/edit')->status);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/wait-list/' . self::MISSING)->status);
        self::assertSame(404, $this->client->get('/' . self::SLUG . '/wait-list/' . self::MISSING . '/edit')->status);
        $gone = $this->client->post('/' . self::SLUG . '/booths/' . self::MISSING . '/delete', []);
        self::assertLessThan(500, $gone->status);
        self::assertSees('that booth is not in this mall', $gone);
        $rate = $this->client->post('/' . self::SLUG . '/rates', ['booth_id' => 'not-a-booth', 'amount' => '10.00']);
        self::assertSees('that booth is not in this mall', $rate);
        $blankRate = $this->client->post('/' . self::SLUG . '/rates', [
            'booth_id' => self::MISSING,
            'amount' => '',
        ]);
        self::assertSees('enter an asking rent', $blankRate);
        $floor = $this->client->post('/' . self::SLUG . '/map/floors/' . self::MISSING, ['name' => 'Ghost']);
        self::assertSees('that floor is not in this mall', $floor);
        $layout = $this->client->post('/' . self::SLUG . '/map/edit', ['layout' => 'not-json']);
        self::assertSees('the map layout is missing', $layout);
        $twice = $this->client->post('/' . self::SLUG . '/map/edit', [
            'layout' => json_encode([
                ['id' => self::MISSING, 'floorId' => self::MISSING, 'x' => 1, 'z' => 1, 'width' => 10, 'depth' => 10],
                ['id' => self::MISSING, 'floorId' => self::MISSING, 'x' => 2, 'z' => 2, 'width' => 10, 'depth' => 10],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSees('a booth is on the map twice', $twice);
        $place = $this->client->post('/' . self::SLUG . '/map/edit', [
            'layout' => json_encode([
                ['id' => self::MISSING, 'floorId' => self::MISSING, 'x' => '', 'z' => 1],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSees('booth place is whole feet', $place);
        $pair = $this->client->post('/' . self::SLUG . '/map/edit', [
            'layout' => json_encode([
                ['id' => self::MISSING, 'floorId' => self::MISSING, 'x' => 1, 'z' => 1, 'width' => 10, 'depth' => null],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSees('set both booth dimensions or leave both empty', $pair);
        $row = $this->client->post('/' . self::SLUG . '/map/edit', [
            'layout' => json_encode(['nope'], JSON_THROW_ON_ERROR),
        ]);
        self::assertSees('the map layout is missing', $row);
        $emptyFloor = $this->client->post('/' . self::SLUG . '/map/floors', ['name' => '']);
        self::assertSees('a floor name is 1 to 40 characters', $emptyFloor);
        $this->client->get('/' . self::SLUG . '/map');
        $this->client->get('/' . self::SLUG . '/parties?status=incoming&q=nobody-here');
        $this->client->get('/' . self::SLUG . '/booths?standing=incoming&q=nobody-here');
        $this->client->get('/' . self::SLUG . '/booths?standing=ending&q=nobody-here');
        $this->client->get('/' . self::SLUG . '/booths?standing=occupied&q=nobody-here');
    }

    public function testInvalidPartyWaitListAndRentForms(): void
    {
        $tag = bin2hex(random_bytes(3));
        $size = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'z' . $tag,
            'name' => 'Size ' . $tag,
            'width_ft' => '12.5',
            'depth_ft' => '8',
        ]);
        self::assertSees('booth size is whole feet', $size);
        $half = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'z' . $tag,
            'name' => 'Size ' . $tag,
            'width_ft' => '12',
            'depth_ft' => '',
        ]);
        self::assertSees('set both booth dimensions or leave both empty', $half);
        $created = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'z' . $tag,
            'name' => 'Size ' . $tag,
            'width_ft' => '10',
            'depth_ft' => '10',
            'amount' => '25.00',
        ]);
        self::assertSees('Booth created.', $created);
        $boothId = HtmlPage::taggedId($created->body, 'tr', 'booth', 'z' . $tag);
        $savedSize = $this->client->post('/' . self::SLUG . '/booths/' . $boothId, [
            'code' => 'z' . $tag,
            'name' => 'Size ' . $tag,
            'width_ft' => '10',
            'depth_ft' => '10',
            'amount' => '30.00',
        ]);
        self::assertSees('Booth saved.', $savedSize);
        $this->client->post('/' . self::SLUG . '/rates', [
            'booth_id' => $boothId,
            'amount' => '35.00',
        ]);
        $this->client->get('/' . self::SLUG . '/booths/' . $boothId . '/edit');

        $dates = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => 'Dee',
            'last_name' => $tag,
            'agreement_starts_on' => '2026-10-15',
            'agreement_ends_on' => '2026-10-01',
        ]);
        self::assertSees('an agreement end cannot be before its start', $dates);
        $badDay = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => 'Dee',
            'last_name' => $tag,
            'agreement_starts_on' => 'October',
        ]);
        self::assertSees('an agreement date is YYYY-MM-DD', $badDay);
        $leap = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => 'Dee',
            'last_name' => $tag,
            'agreement_starts_on' => '2026-02-31',
        ]);
        self::assertSees('an agreement date is YYYY-MM-DD', $leap);
        $phone = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => 'Dee',
            'last_name' => $tag,
            'phone' => str_repeat('9', 41),
        ]);
        self::assertSees('that phone is too long', $phone);
        $email = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => 'Dee',
            'last_name' => $tag,
            'email' => 'not-mail',
        ]);
        self::assertSees('that email is not an email address', $email);
        $nameless = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => '',
            'last_name' => $tag,
        ]);
        self::assertSees('a first name is required', $nameless);
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'd' . $tag,
            'first_name' => 'Dee',
            'last_name' => $tag,
            'store_take' => '12.05',
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', 'd' . $tag);
        $edit = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId . '/edit');
        self::assertSees('Overridden', $edit);
        $badSave = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId, [
            'code' => 'd' . $tag,
            'first_name' => '',
            'last_name' => $tag,
            'store_take' => '12.05',
        ]);
        self::assertSees('a first name is required', $badSave);
        $active = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/standing', [
            'standing' => 'active',
        ]);
        self::assertSees('Vendor is active.', $active);
        $inactive = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/standing', [
            'standing' => 'inactive',
        ]);
        self::assertSees('Vendor is inactive.', $inactive);
        $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/standing', [
            'standing' => 'active',
        ]);
        $nextTag = 'n' . $tag;
        $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $nextTag,
            'name' => $nextTag,
            'width_ft' => '10',
            'depth_ft' => '10',
        ]);
        $nextBooth = HtmlPage::taggedId(
            $this->client->get('/' . self::SLUG . '/booths?q=' . rawurlencode($nextTag))->body,
            'tr',
            'booth',
            $nextTag
        );
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => '',
            'rent' => '90.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);
        $stayPage = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSame(1, preg_match('/name="assignment_id" value="([0-9a-fA-F-]{36})"/', $stayPage->body, $stay));
        $moved = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/move', [
            'assignment_id' => $stay[1],
            'booth_id' => $nextBooth,
            'ends_on' => '2026-09-15',
            'rent' => '90.00',
        ]);
        self::assertSees('Vendor moved.', $moved);
        $missingMove = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/move', [
            'assignment_id' => self::MISSING,
            'booth_id' => $boothId,
            'ends_on' => '2026-10-01',
            'rent' => '10.00',
        ]);
        self::assertSees('That booth stay is already finished.', $missingMove);
        $badAssign = $this->client->post('/' . self::SLUG . '/assignments', [
            'vendor_id' => 'not-a-vendor',
            'booth_id' => $boothId,
            'starts_on' => '2026-10-01',
        ]);
        self::assertLessThan(500, $badAssign->status);
        $signIn = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/sign-in', [
            'username' => '',
            'display_name' => 'Dee',
            'password' => 'short',
            'email' => 'dee@example.test',
        ]);
        self::assertLessThan(500, $signIn->status);

        $last = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => '',
            'email' => 'wait-' . $tag . '@example.test',
            'phone' => '555-0100',
        ]);
        self::assertSees('a last name is required', $last);
        $noPhone = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => 'wait-' . $tag . '@example.test',
            'phone' => '',
        ]);
        self::assertSees('a phone number is required', $noPhone);
        $noEmail = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => '',
            'phone' => '555-0100',
        ]);
        self::assertSees('an email is required', $noEmail);
        $notes = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => 'wait-' . $tag . '@example.test',
            'phone' => '555-0100',
            'notes' => str_repeat('n', 2001),
        ]);
        self::assertSees('those notes are too long', $notes);
        $longPhone = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => $tag,
            'email' => 'wait-' . $tag . '@example.test',
            'phone' => str_repeat('9', 41),
        ]);
        self::assertSees('that phone is too long', $longPhone);
        $added = $this->client->post('/' . self::SLUG . '/wait-list', [
            'first_name' => 'Willa',
            'last_name' => 'Willa',
            'email' => 'same-' . $tag . '@example.test',
            'phone' => '555-0100',
        ]);
        self::assertSees('Added to the wait list.', $added);
        $waitId = $this->match($added->body, '#/wait-list/([0-9a-fA-F-]{36})#');
        $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $card = $this->client->get('/' . self::SLUG . '/wait-list/' . $waitId);
        self::assertSees('Willa', $card);
        $this->client->get('/' . self::SLUG . '/wait-list?status=all&q=' . $tag);
        $this->client->get('/' . self::SLUG . '/wait-list?status=placed&q=nobody');
        $this->client->get('/' . self::SLUG . '/wait-list?status=nope');
        $badStatus = $this->client->post('/' . self::SLUG . '/wait-list/' . $waitId, [
            'first_name' => 'Willa',
            'last_name' => 'Willa',
            'email' => 'same-' . $tag . '@example.test',
            'phone' => '555-0100',
            'status' => 'lost',
        ]);
        self::assertSees('that status is not on the wait list', $badStatus);
        $this->client->get('/' . self::SLUG . '/wait-list/' . $waitId . '/edit');
        $missingWait = $this->client->post('/' . self::SLUG . '/wait-list/' . self::MISSING . '/delete', []);
        self::assertSees('That person is not on the wait list.', $missingWait);

        $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $receipt = $this->client->get('/' . self::SLUG . '/rent/receipt/' . self::MISSING);
        self::assertSees('that rent receipt is missing', $receipt);
        $waive = $this->client->post('/' . self::SLUG . '/rent/waive', []);
        self::assertSees('Choose a late fee.', $waive);
        $collect = $this->client->get('/' . self::SLUG . '/rent/collect');
        self::assertSees('Choose who is paying rent.', $collect);
        $chargePrepaid = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => '2026-10-01',
        ]);
        self::assertLessThan(500, $chargePrepaid->status);
        $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $undo = $this->client->post('/' . self::SLUG . '/payout/undo', [
            'vendor_id' => $vendorId,
            'run_id' => self::MISSING,
            'reason' => '',
        ]);
        self::assertSees('a payout reversal needs the operator and a reason', $undo);
        $payout = $this->client->post('/' . self::SLUG . '/payout', ['vendor_id' => $vendorId]);
        self::assertLessThan(500, $payout->status);
        $this->client->get('/' . self::SLUG . '/payout?vendor=' . $vendorId);
        $deleted = $this->client->post('/' . self::SLUG . '/vendors/' . $vendorId . '/delete', []);
        self::assertTrue(
            str_contains($deleted->body, 'Vendor deleted.')
            || str_contains($deleted->body, 'That vendor still has inventory.')
            || HtmlPage::error($deleted->body) !== ''
        );
    }

    public function testAssignmentRentChangeAndTimeline(): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->saveMoney(false);
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'h' . $tag,
            'first_name' => 'Hal',
            'last_name' => $tag,
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', 'h' . $tag);
        $booth = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'rh' . $tag,
            'name' => 'History ' . $tag,
            'amount' => '50.00',
        ]);
        self::assertSees('Booth created.', $booth);
        $boothId = HtmlPage::taggedId($booth->body, 'tr', 'booth', 'rh' . $tag);
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => '2026-01-01',
            'ends_on' => '',
            'rent' => '50.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);
        $page = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSees('Change rent', $page);
        self::assertSame(1, preg_match(
            '#action="/' . self::SLUG . '/assignments/([0-9a-fA-F-]{36})/rent"#',
            $page->body,
            $rentForm
        ));
        $changed = $this->client->post('/' . self::SLUG . '/assignments/' . $rentForm[1] . '/rent', [
            'rent' => '65.00',
            'starts_on' => '2026-04-01',
        ]);
        self::assertSees('Rent rate saved.', $changed);
        $again = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSees('$65.00', $again);
        self::assertSees('01-01-2026', $again);
        self::assertSees('$50.00', $again);
        self::assertSees('04-01-2026', $again);
        $future = $this->client->post('/' . self::SLUG . '/assignments/' . $rentForm[1] . '/rent', [
            'rent' => '80.00',
            'starts_on' => '2027-01-01',
        ]);
        self::assertSees('Rent rate saved.', $future);
        $asOf = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSees('$65.00', $asOf);
        self::assertSees('$80.00', $asOf);
        self::assertSees('01-01-2027', $asOf);
        $check = $this->client->get('/' . self::SLUG . '/vendor?as=' . $vendorId);
        self::assertSees('Your check', $check);
        self::assertSees('$65.00', $check);
        self::assertStringNotContainsString('$80.00', $check->body);
        $oldCharge = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => '2026-03-01',
        ], 120);
        self::assertSees('Rent charged.', $oldCharge);
        $newCharge = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => '2026-04-01',
        ], 120);
        self::assertSees('Rent charged.', $newCharge);
        $payouts = $this->client->get('/' . self::SLUG . '/payouts');
        self::assertSame(200, $payouts->status);
        $dup = $this->client->post('/' . self::SLUG . '/assignments/' . $rentForm[1] . '/rent', [
            'rent' => '70.00',
            'starts_on' => '2026-04-01',
        ]);
        self::assertSees('That stay already has a rent rate on that date.', $dup);
        $before = $this->client->post('/' . self::SLUG . '/assignments/' . $rentForm[1] . '/rent', [
            'rent' => '70.00',
            'starts_on' => '2025-12-01',
        ]);
        self::assertSees('The effective date cannot be before the stay starts.', $before);
    }

    public function testRentCollectWaiveAndPrepaidOwing(): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->activateRegister();
        $this->saveMoney(false);
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'r' . $tag,
            'first_name' => 'Ray',
            'last_name' => $tag,
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::taggedId($this->client->get('/' . self::SLUG . '/parties')->body, 'tr', 'vendor', 'r' . $tag);
        $booth = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'b' . $tag,
            'name' => 'Rent ' . $tag,
            'amount' => '40.00',
        ]);
        self::assertSees('Booth created.', $booth);
        $boothId = HtmlPage::taggedId($booth->body, 'tr', 'booth', 'b' . $tag);
        $assigned = $this->client->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => '2026-09-01',
            'ends_on' => '',
            'rent' => '40.00',
        ]);
        self::assertSees('Booth assigned.', $assigned);
        $charged = $this->client->post('/' . self::SLUG . '/rent/charge', [
            'booth_id' => $boothId,
            'posting_date' => '2026-09-01',
        ]);
        self::assertSees('Rent charged.', $charged);
        $lateOn = [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'operating_mode' => 'both',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
            'late_fees_enabled' => '1',
            'late_fee_grace_days' => '0',
            'late_fee_first' => '5.00',
            'late_fee_next' => '5.00',
        ];
        self::assertSees('Money saved.', $this->client->post('/' . self::SLUG . '/settings', $lateOn));
        $this->client->get('/' . self::SLUG . '/rent');
        $collect = $this->client->get('/' . self::SLUG . '/rent/collect?vendor=' . $vendorId . '&booth=' . $boothId);
        self::assertSees('They owe', $collect);
        $none = $this->client->post('/' . self::SLUG . '/rent/waive', [
            'vendor_id' => $vendorId,
            'booth_id' => $boothId,
        ]);
        self::assertSees('Choose a late fee.', $none);
        if (preg_match('/name="waive\[\]" value="([0-9a-fA-F-]{36})"/', $collect->body, $fee) === 1) {
            $missed = $this->client->post('/' . self::SLUG . '/rent/waive', [
                'vendor_id' => $vendorId,
                'booth_id' => $boothId,
                'journal_id' => self::MISSING,
            ]);
            self::assertSees('Choose a late fee.', $missed);
            $one = $this->client->post('/' . self::SLUG . '/rent/waive', [
                'vendor_id' => $vendorId,
                'booth_id' => $boothId,
                'journal_id' => $fee[1],
            ]);
            self::assertTrue(
                str_contains($one->body, 'Late fee waived.') || str_contains($one->body, 'They owe'),
                HtmlPage::error($one->body)
            );
            $again = $this->client->get('/' . self::SLUG . '/rent/collect?vendor=' . $vendorId . '&booth=' . $boothId);
            if (preg_match('/name="waive\[\]" value="([0-9a-fA-F-]{36})"/', $again->body, $more) === 1) {
                $waivedPay = $this->client->post('/' . self::SLUG . '/rent/payment', [
                    'vendor_id' => $vendorId,
                    'booth_id' => $boothId,
                    'waive' => $more[1],
                    'waive_note' => 'coverage',
                ]);
                self::assertTrue(
                    str_contains($waivedPay->body, 'Late fee waived.')
                    || str_contains($waivedPay->body, 'Late fees waived.')
                    || str_contains($waivedPay->body, 'They owe')
                    || str_contains($waivedPay->body, 'Rent receipt'),
                    HtmlPage::error($waivedPay->body)
                );
            }
            $all = $this->client->post('/' . self::SLUG . '/rent/waive', [
                'vendor_id' => $vendorId,
                'booth_id' => $boothId,
                'all' => '1',
            ]);
            self::assertLessThan(500, $all->status);
        }
        $this->saveMoney(true);
        try {
            $prepaid = $this->client->get('/' . self::SLUG . '/rent/collect?vendor=' . $vendorId);
            self::assertLessThan(500, $prepaid->status);
            $this->client->get('/' . self::SLUG . '/rent');
        } finally {
            $this->saveMoney(false);
        }
    }

    public function testTheRentBookFiltersAndPrepaidList(): void
    {
        $this->client->get('/' . self::SLUG . '/rent?q=zzzz-no-booth&standing=owed');
        $this->client->get('/' . self::SLUG . '/rent?q=&standing=due');
        $this->client->get('/' . self::SLUG . '/rent?standing=charged');
        $this->client->get('/' . self::SLUG . '/rent?standing=owed');
        $this->client->get('/' . self::SLUG . '/rent?month=2020-01&q=nobody&standing=due');
        $empty = $this->client->get('/' . self::SLUG . '/rent?q=zzzz-no-booth');
        self::assertTrue(
            str_contains($empty->body, 'No booths match.')
            || str_contains($empty->body, 'No rent for this month.')
            || str_contains($empty->body, '<h1 class="h1">Rent</h1>')
        );
        $this->saveMoney(true);
        try {
            $nobody = $this->client->get('/' . self::SLUG . '/rent?q=nobody-owes-this');
            self::assertSees('Who owes the store', $nobody);
            self::assertTrue(
                str_contains($nobody->body, 'Nobody owes rent.')
                || str_contains($nobody->body, 'Owes the store')
            );
            $this->client->get('/' . self::SLUG . '/rent');
            $this->activateRegister();
            $withDrawer = $this->client->get('/' . self::SLUG . '/rent');
            self::assertSees('Who owes the store', $withDrawer);
            $blocked = $this->client->post('/' . self::SLUG . '/rent/charge', [
                'booth_id' => self::MISSING,
                'posting_date' => '2026-10-01',
            ]);
            self::assertSees('this rent comes out of sales', $blocked);
        } finally {
            $this->saveMoney(false);
        }
        $restored = $this->client->get('/' . self::SLUG . '/rent');
        self::assertSees('<h1 class="h1">Rent</h1>', $restored);
    }

    private function saveMoney(bool $prepaid): void
    {
        $fields = [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'operating_mode' => 'both',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
            'late_fee_grace_days' => '0',
        ];
        if ($prepaid) {
            $fields['rent_prepaid_from_prior_sales'] = '1';
        }
        $saved = $this->client->post('/' . self::SLUG . '/settings', $fields);
        self::assertSees('Money saved.', $saved);
    }

    private function activateRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (!str_contains($page->body, '/open')) {
            $created = $this->client->post('/' . self::SLUG . '/registers', [
                'name' => 'Rent ' . bin2hex(random_bytes(2)),
            ]);
            $page = $created;
        }
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $open) !== 1) {
            return;
        }
        $this->client->post($open[1], [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            'count_10000' => '0',
            'count_5000' => '0',
            'count_2000' => '0',
            'count_1000' => '0',
            'count_500' => '0',
            'count_100' => '0',
            'count_25' => '0',
            'count_10' => '0',
            'count_5' => '0',
            'count_1' => '0',
        ]);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
