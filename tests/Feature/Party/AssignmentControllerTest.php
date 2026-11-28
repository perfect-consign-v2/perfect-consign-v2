<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\AssignmentController;
use PerfectConsign\Feature\Party\AssignmentOffer;
use PerfectConsign\Feature\Party\AssignmentOutcome;
use PerfectConsign\Feature\Party\BoothBooking;
use PerfectConsign\Feature\Party\BoothStays;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\RentHistory;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Feature\Party\VendorBalances;
use PerfectConsign\Feature\Party\VendorForm;
use PerfectConsign\Feature\Party\VendorPage;
use PerfectConsign\Feature\Party\VendorPlacement;
use PerfectConsign\Feature\Party\VendorRoster;
use PerfectConsign\Feature\Party\VendorYearPaid;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

/**
 * Only the owner reaches Cancel reservation (the database refusals are
 * proven in prove_assign_booth.sql and BoothBookingTest), and a stay the
 * books do not know belongs to no vendor.
 */
final class AssignmentControllerTest extends TestCase
{
    use CapturesPage;

    private const STAY = '01900000-0000-7000-8000-0000000000c9';

    public function testOnlyTheOwnerReachesCancelAndAnUnknownStayHasNoVendor(): void
    {
        $manager = $this->door();
        $manager->session->set('actor_role', 'manager');
        $hidden = $this->capture(function () use ($manager): void {
            $this->controller($manager)->cancelReservation(MallDoor::SLUG, self::STAY);
        });
        self::assertStringContainsString('<title>Not found', $hidden);
        self::assertStringNotContainsString('Reservation cancelled', $hidden);

        self::assertSame('', $this->outcome($this->door())->vendorOf(self::STAY));
    }

    private function door(): MallDoor
    {
        return MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/assignments/' . self::STAY . '/cancel', [], [
            'csrf' => 'csrf-token',
            'outcome' => 'keep',
            'reason' => 'No show',
        ]));
    }

    private function controller(MallDoor $door): AssignmentController
    {
        $audit = QuietAudit::journal($door->statements, $door->actor);
        $stays = new AssignmentBook($door->statements);

        return new AssignmentController(
            $door->app,
            new AssignmentOffer(new PartyStore($door->statements, $audit, $stays), $stays),
            new BoothStays($stays, $audit),
            new BoothBooking($door->statements, $door->postings, $audit),
            $this->outcome($door),
            new ActiveRegister($door->pdo, $door->session, $door->mall, new MallClock($door->mall)),
            $door->mall,
        );
    }

    private function outcome(MallDoor $door): AssignmentOutcome
    {
        $audit = QuietAudit::journal($door->statements, $door->actor);
        $stays = new AssignmentBook($door->statements);
        $parties = new PartyStore($door->statements, $audit, $stays);
        $roster = new VendorRoster(new VendorPlacement());
        $runs = new PayoutRunStore($door->statements, new OccupiedPack($door->statements));
        $balances = new VendorBalances(
            new PayoutStore($door->pdo, $door->statements, $runs, new PayoutWindow()),
            new VendorClawback($door->ledger, $door->statements),
            new VendorYearPaid($door->statements),
            new RentHistory($door->statements),
        );
        $forms = new VendorForm($door->app, $door->mall, new SellerPaths($door->mall));
        $staff = new StaffStore($door->statements, new RecoveryAddress(), new PasswordChoice());
        $page = new VendorPage($door->app, $parties, $stays, $door->mall, $roster, $balances, new \PerfectConsign\Feature\Party\VendorMoveOut($door->statements), $forms, $staff);

        return new AssignmentOutcome($door->app, $parties, $stays, $page, $door->mall, $roster, new SellerPaths($door->mall));
    }
}
