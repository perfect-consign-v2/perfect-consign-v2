<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use RuntimeException;

/**
 * Each case posts one user action and asserts the sentence the screen returns.
 */
final class UserActionCest
{
    public function aWrongEmailAndABlankEmailAreRefused(FunctionalTester $I): void
    {
        $client = $I->probeClient();
        $client->get('/');
        $wrong = $client->post('/', [
            'email' => 'nobody@example.test',
            'password' => 'not-the-password',
        ]);
        $I->assertStringContainsString('That email or password is wrong.', $wrong->body);
        $blank = $client->post('/', [
            'email' => '',
            'password' => '',
        ]);
        $I->assertStringContainsString('That email or password is wrong.', $blank->body);
    }

    public function aMissingEmailAndTheOwnerEmailGetTheSameResetSentence(FunctionalTester $I): void
    {
        $client = $I->probeClient();
        $client->get('/forgot');
        $missing = $client->post('/forgot', ['email' => 'nobody@example.test']);
        $I->assertStringContainsString(ResetNotice::SENT, $missing->body);
        $owner = $client->post('/forgot', ['email' => ProbeMall::EMAIL]);
        $I->assertStringContainsString(ResetNotice::SENT, $owner->body);
    }

    public function aVendorIsRefusedUntilTheNameAndStayAreUsable(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $blank = $client->post($this->path('/vendors'), [
            'code' => 'blank',
            'first_name' => '',
            'last_name' => 'Lane',
        ]);
        $I->assertStringContainsString('a first name is required', $blank->body);
        $stay = $client->post($this->path('/vendors'), [
            'code' => 'stay' . bin2hex(random_bytes(2)),
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'keep_days' => 'soon',
        ]);
        $I->assertStringContainsString('how long goods stay is a number of days', $stay->body);
        $code = 'v' . bin2hex(random_bytes(3));
        $saved = $client->post($this->path('/vendors'), [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        $I->assertStringContainsString('Vendor created.', $saved->body);
        $I->assertStringContainsString($code, $saved->body);
    }

    public function anItemIsRefusedUntilThePriceIsMoney(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $vendorId = $I->probeSeed()->vendorId;
        $bad = $client->post($this->path('/inventory'), $this->item($vendorId, 'abc'));
        $I->assertStringContainsString('enter an amount in dollars and cents', $bad->body);
        $zero = $client->post($this->path('/inventory'), $this->item($vendorId, '0'));
        $I->assertStringContainsString('the amount must be greater than zero', $zero->body);
        $saved = $client->post($this->path('/inventory'), $this->item($vendorId, '12.00'));
        $I->assertStringContainsString('Item created.', $saved->body);
    }

    public function aScanAndABadTenderAreRefused(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $this->openRegister($client);
        $blank = $client->post($this->path('/pos/scan'), ['scan' => '']);
        $I->assertStringContainsString('scan a barcode or a tag', $blank->body);
        $missing = $client->post($this->path('/pos/scan'), ['scan' => 'no-such-tag-' . bin2hex(random_bytes(3))]);
        $I->assertStringContainsString('that item is not on the floor', $missing->body);
        $itemCode = $I->probeSeed()->itemCode;
        $added = $client->post($this->path('/pos/scan'), ['scan' => $itemCode]);
        $I->assertStringContainsString($itemCode, $added->body);
        $bad = $client->post($this->path('/pos/pay'), ['tender_cash' => 'abc']);
        $I->assertStringContainsString('enter an amount in dollars and cents', $bad->body);
        $empty = $client->post($this->path('/pos/pay'), []);
        $I->assertStringContainsString('a sale debits each tender once', $empty->body);
        $discarded = $client->post($this->path('/pos/discard'), []);
        $I->assertStringContainsString('Ticket discarded.', $discarded->body);
    }

    public function aBoothIsRefusedUntilTheSizeIsWholeFeet(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $wide = $client->post($this->path('/booths'), [
            'code' => 'wide' . bin2hex(random_bytes(2)),
            'name' => 'Wide',
            'width_ft' => 'wide',
            'depth_ft' => '10',
        ]);
        $I->assertStringContainsString('booth size is whole feet', $wide->body);
        $half = $client->post($this->path('/booths'), [
            'code' => 'half' . bin2hex(random_bytes(2)),
            'name' => 'Half',
            'width_ft' => '10',
            'depth_ft' => '',
        ]);
        $I->assertStringContainsString('set both booth dimensions or leave both empty', $half->body);
        $code = 'b' . bin2hex(random_bytes(3));
        $saved = $client->post($this->path('/booths'), [
            'code' => $code,
            'name' => $code,
        ]);
        $I->assertStringContainsString('Booth created.', $saved->body);
        $rent = $client->post($this->path('/rates'), [
            'booth_id' => 'not-a-booth',
            'amount' => '10.00',
        ]);
        $I->assertStringContainsString('that booth is not in this mall', $rent->body);
    }

    public function aCustomerIsRefusedUntilTheNameAndEmailAreUsable(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $blank = $client->post($this->path('/customers'), ['customer_name' => '']);
        $I->assertStringContainsString('Enter the customer name.', $blank->body);
        $email = $client->post($this->path('/customers'), [
            'customer_name' => 'Ada Lane',
            'customer_email' => 'not-an-email',
        ]);
        $I->assertStringContainsString('Enter a real email or leave it blank.', $email->body);
        $saved = $client->post($this->path('/customers'), [
            'customer_name' => 'Ada ' . bin2hex(random_bytes(2)),
        ]);
        $I->assertStringContainsString('Customer created.', $saved->body);
    }

    public function aWaitListEntryIsRefusedUntilThePersonCanBeReached(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $blank = $client->post($this->path('/wait-list'), [
            'first_name' => '',
            'last_name' => 'Lane',
            'email' => 'ada@example.test',
            'phone' => '555-0100',
        ]);
        $I->assertStringContainsString('a first name is required', $blank->body);
        $email = $client->post($this->path('/wait-list'), [
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'email' => 'not-an-email',
            'phone' => '555-0100',
        ]);
        $I->assertStringContainsString('that email is not an email address', $email->body);
        $phone = $client->post($this->path('/wait-list'), [
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'email' => 'ada@example.test',
            'phone' => '',
        ]);
        $I->assertStringContainsString('a phone number is required', $phone->body);
        $tag = bin2hex(random_bytes(3));
        $saved = $client->post($this->path('/wait-list'), [
            'first_name' => 'Ada',
            'last_name' => $tag,
            'email' => $tag . '@example.test',
            'phone' => '555-0100',
        ]);
        $I->assertStringContainsString('Added to the wait list.', $saved->body);
    }

    public function aStaffLoginIsRefusedUntilTheRoleNameAndPasswordAreUsable(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $role = $client->post($this->path('/staff'), ['role' => 'intern']);
        $I->assertStringContainsString('Choose a role.', $role->body);
        $name = $client->post($this->path('/staff'), [
            'role' => 'cashier',
            'username' => '1bad',
            'display_name' => 'Bad Name',
            'password' => 'long-enough',
            'email' => 'bad@example.test',
        ]);
        $I->assertStringContainsString('Use a sign-in name of letters, numbers, and hyphens.', $name->body);
        $display = $client->post($this->path('/staff'), [
            'role' => 'cashier',
            'username' => 'c' . bin2hex(random_bytes(3)),
            'display_name' => '',
            'password' => 'long-enough',
            'email' => 'blank@example.test',
        ]);
        $I->assertStringContainsString('Enter the name people will see.', $display->body);
        $short = $client->post($this->path('/staff'), [
            'role' => 'cashier',
            'username' => 'c' . bin2hex(random_bytes(3)),
            'display_name' => 'Short Password',
            'password' => 'short',
            'email' => 'short@example.test',
        ]);
        $I->assertStringContainsString('Use a password of at least 8 characters.', $short->body);
        $username = 'c' . bin2hex(random_bytes(3));
        $saved = $client->post($this->path('/staff'), [
            'role' => 'cashier',
            'username' => $username,
            'display_name' => 'Added Cashier',
            'password' => 'cashier-added',
            'email' => $username . '@example.test',
        ]);
        $I->assertStringContainsString('Cashier added.', $saved->body);
    }

    public function aPasswordChangeIsRefusedWhenItDoesNotMatch(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $wrong = $client->post($this->path('/password'), [
            'current_password' => 'not-the-password',
            'password' => 'another-password',
            'confirm' => 'another-password',
        ]);
        $I->assertStringContainsString('That password is wrong.', $wrong->body);
        $mismatch = $client->post($this->path('/password'), [
            'current_password' => ProbeMall::PASSWORD,
            'password' => 'another-password',
            'confirm' => 'different-password',
        ]);
        $I->assertStringContainsString('Those new passwords do not match.', $mismatch->body);
    }

    public function aStoreSignupRefusesABlankName(FunctionalTester $I): void
    {
        $client = $I->probeClient();
        $page = $client->get('/signup');
        if (str_contains($page->body, 'Self-serve signup is turned off.')) {
            $I->assertStringContainsString('Self-serve signup is turned off.', $page->body);

            return;
        }
        if (str_contains($page->body, 'Store signup is not available.')) {
            $I->assertStringContainsString('Store signup is not available.', $page->body);

            return;
        }
        $refused = $client->post('/signup', [
            'store_name' => '',
            'email' => 'owner@example.test',
            'password' => 'long-enough',
            'password_confirm' => 'long-enough',
        ]);
        $I->assertStringContainsString('Enter a store name.', $refused->body);
    }

    public function aCashierBooksAndVendorCannotOpenOwnerPages(FunctionalTester $I): void
    {
        $seed = $I->probeSeed();
        $slug = ProbeMall::SLUG;
        $cashier = $I->probeClient();
        $cashier->signIn($slug, $seed->cashierUsername, $seed->cashierPassword);
        $settings = $cashier->get('/' . $slug . '/settings');
        $I->assertStringContainsString('This address is not a page in', $settings->body);
        $books = $I->probeClient();
        $books->signIn($slug, $seed->booksUsername, $seed->booksPassword);
        $staff = $books->get('/' . $slug . '/staff');
        $I->assertStringContainsString('This address is not a page in', $staff->body);
        $vendor = $I->probeClient();
        $vendor->signIn($slug, $seed->vendorUsername, $seed->vendorPassword);
        $payouts = $vendor->get('/' . $slug . '/payouts');
        $I->assertStringContainsString('This address is not a page in', $payouts->body);
        $register = $vendor->get('/' . $slug . '/pos');
        $I->assertStringContainsString('This address is not a page in', $register->body);
    }

    public function aRentChargeWithoutAnAssignmentIsRefused(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $refused = $client->post($this->path('/rent/charge'), [
            'booth_id' => '00000000-0000-0000-0000-000000000000',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        $I->assertStringContainsString(
            'a rent charge requires an assignment that covers the charge date',
            $refused->body
        );
        $I->assertStringNotContainsString('Rent charged.', $refused->body);
    }

    public function aMallSaleIsRefusedUntilThePercentAndDatesFit(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $tag = bin2hex(random_bytes(3));
        $bad = $client->post($this->path('/mall-sale'), [
            'name' => 'Sale ' . $tag,
            'starts_on' => '2094-04-01',
            'ends_on' => '2094-04-02',
            'percent' => 'ten',
            'absorbed_by' => 'mall',
        ]);
        $I->assertStringContainsString('Name who absorbs the sale and the percent.', $bad->body);
        $high = $client->post($this->path('/mall-sale'), [
            'name' => 'Sale ' . $tag,
            'starts_on' => '2094-04-01',
            'ends_on' => '2094-04-02',
            'percent' => '50.01',
            'absorbed_by' => 'vendor',
        ]);
        $I->assertStringContainsString('The percent has to be between 0 and 50.', $high->body);
        $year = random_int(2500, 2599);
        $month = str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string) random_int(1, 28), 2, '0', STR_PAD_LEFT);
        $start = $year . '-' . $month . '-' . $day;
        $saved = $client->post($this->path('/mall-sale'), [
            'name' => 'Mall ' . $tag,
            'starts_on' => $start,
            'ends_on' => $start,
            'percent' => '10.5',
            'absorbed_by' => 'mall',
        ]);
        $I->assertStringContainsString('Mall sale saved.', $saved->body);
        $overlap = $client->post($this->path('/mall-sale'), [
            'name' => 'Overlap ' . $tag,
            'starts_on' => $start,
            'ends_on' => $start,
            'percent' => '5',
            'absorbed_by' => 'vendor',
        ]);
        $I->assertStringContainsString('exclusion constraint', $overlap->body);
        $backwards = $client->post($this->path('/mall-sale'), [
            'name' => 'Back ' . $tag,
            'starts_on' => '2094-06-10',
            'ends_on' => '2094-06-01',
            'percent' => '1',
            'absorbed_by' => 'mall',
        ]);
        $I->assertStringContainsString('That could not be saved.', $backwards->body);
    }

    public function aFloorNoteIsRefusedUntilTheKindIsMissingOrMisplaced(FunctionalTester $I): void
    {
        $client = $this->owner($I);
        $seed = $I->probeSeed();
        $lost = $client->post($this->path('/floor'), [
            'kind' => 'lost',
            'code' => $seed->itemCode,
            'noted_on' => ProbeMall::POSTING_DATE,
            'noted_by' => ProbeMall::USERNAME,
        ]);
        $I->assertStringContainsString('Choose missing or misplaced.', $lost->body);
        $saved = $client->post($this->path('/floor'), [
            'kind' => 'missing',
            'code' => $seed->itemCode,
            'noted_on' => ProbeMall::POSTING_DATE,
            'noted_by' => ProbeMall::USERNAME,
        ]);
        $I->assertStringContainsString('Floor note saved.', $saved->body);
        $pickup = $client->post($this->path('/pickups'), [
            'code' => 'no-such-tag',
            'picked_up_on' => ProbeMall::POSTING_DATE,
            'received_by' => ProbeMall::USERNAME,
        ]);
        $I->assertStringContainsString('expected a string', $pickup->body);
        $I->assertStringNotContainsString('Pickup saved.', $pickup->body);
    }

    private function owner(FunctionalTester $I): FormClient
    {
        $client = $I->probeClient();
        $home = $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $I->assertSame(200, $home->status);

        return $client;
    }

    private function path(string $suffix): string
    {
        return '/' . ProbeMall::SLUG . $suffix;
    }

    /**
     * @return array<string, string>
     */
    private function item(string $vendorId, string $price): array
    {
        $tag = 't' . bin2hex(random_bytes(3));

        return [
            'code' => $tag,
            'description' => 'Probe ' . $tag,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'barcode' => 'BC-' . $tag,
            'intake_on' => '2026-09-01',
            'placement' => 'consigned',
        ];
    }

    private function openRegister(FormClient $client): void
    {
        $slug = ProbeMall::SLUG;
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
