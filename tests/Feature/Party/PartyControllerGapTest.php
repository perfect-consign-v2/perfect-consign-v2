<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\AssignmentOffer;
use PerfectConsign\Feature\Party\PartyController;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Feature\Party\VendorBalances;
use PerfectConsign\Feature\Party\VendorForm;
use PerfectConsign\Feature\Party\VendorPage;
use PerfectConsign\Feature\Party\VendorPlacement;
use PerfectConsign\Feature\Party\VendorRoster;
use PerfectConsign\Feature\Party\VendorYearPaid;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

final class PartyControllerGapTest extends TestCase
{
    use CapturesPage;

    public function testAPartyWithNoRoleRecordStaysOnThePartyPage(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/parties/' . MallDoor::PARTY, [], []));
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->party(MallDoor::SLUG, MallDoor::PARTY);
        });

        self::assertStringContainsString('This party has no vendor, customer, or wait-list record to open.', $html);
        self::assertStringContainsString('Pat', $html);
    }

    public function testDeletingAVendorRedirectsWhenTheDeleteLands(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/delete'));
        $door->statements->affectedCount = 1;
        $this->capture(function () use ($door): void {
            $this->controller($door)->remove(MallDoor::SLUG, MallDoor::VENDOR);
        });

        self::assertSame('Vendor deleted.', $door->bag['notice'] ?? null);
    }

    public function testDeletingAMissingVendorShowsTheStoreMessage(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/delete'));
        $door->statements->affectedCount = 0;
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->remove(MallDoor::SLUG, MallDoor::VENDOR);
        });

        self::assertStringContainsString('that vendor is not in this mall', $html);
    }

    public function testStandingRefusesWhenTheBooksRefuseAndTheVendorIsGone(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/standing', [
            'standing' => 'inactive',
        ]));
        $door->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->setStanding(MallDoor::SLUG, MallDoor::VENDOR);
        });

        self::assertStringContainsString('Not found', $html);
    }

    /**
     * @param array<string, string> $post
     */
    private function post(string $path, array $post = []): Request
    {
        return new Request('POST', $path, [], ['csrf' => 'csrf-token'] + $post);
    }

    private function controller(MallDoor $door): PartyController
    {
        $audit = new JournalAudit($door->actor, new AuditLog($door->statements));
        $stays = new AssignmentBook($door->statements);
        $parties = new PartyStore($door->statements, $audit, $stays);
        $roster = new VendorRoster(new VendorPlacement());
        $runs = new PayoutRunStore($door->statements, new OccupiedPack($door->statements));
        $balances = new VendorBalances(
            new PayoutStore($door->pdo, $door->statements, $runs, new PayoutWindow()),
            new VendorClawback($door->ledger, $door->statements),
            new VendorYearPaid($door->statements),
        );
        $forms = new VendorForm($door->app, $door->mall, new SellerPaths($door->mall));
        $staff = new StaffStore($door->statements, new RecoveryAddress(), new PasswordChoice());

        return new PartyController(
            $door->app,
            $parties,
            $stays,
            $door->mall,
            $roster,
            $balances,
            $forms,
            new VendorPage(
                $door->app,
                $parties,
                $stays,
                $door->mall,
                $roster,
                $balances,
                new AssignmentOffer($parties, $stays),
                $forms,
                $staff,
            ),
        );
    }
}
