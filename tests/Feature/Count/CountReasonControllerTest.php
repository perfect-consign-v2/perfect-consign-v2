<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Count;

use PerfectConsign\Bootstrap\RoleGate;
use PerfectConsign\Feature\Count\CountReasonController;
use PerfectConsign\Feature\Count\CountReasons;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

/**
 * Settings → Item counts refuses waiting days that are not 1 to 365 before
 * anything reaches the database, and only the owner may let the store keep a
 * piece left behind (the database rules are proven in prove_item_quantity.sql).
 */
final class CountReasonControllerTest extends TestCase
{
    use CapturesPage;

    public function testWaitingDaysOutsideOneToThreeSixtyFiveStayOnThePage(): void
    {
        foreach (['0', '366', 'ten', '-3'] as $typed) {
            $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/settings/counts/wait', [], [
                'csrf' => 'csrf-token',
                'abandon_wait_days' => $typed,
            ]));
            // Nothing may reach the database: a write here would be refused.
            $door->statements->transactionRefuses = true;
            $controller = new CountReasonController($door->app, new CountReasons($door->statements, QuietAudit::journal($door->statements, $door->actor)));
            $page = $this->capture(function () use ($controller): void {
                $controller->wait(MallDoor::SLUG);
            });
            self::assertStringContainsString('Enter the days as a whole number from 1 to 365, or leave it empty.', $page, $typed);
        }
    }

    public function testOnlyTheOwnerKeepsAPieceLeftBehind(): void
    {
        $rest = '/vendors/01900000-0000-7000-8000-0000000000c9/close-out';
        self::assertTrue(RoleGate::allows('owner', $rest . '/keep', 'POST'));
        self::assertFalse(RoleGate::allows('manager', $rest . '/keep', 'POST'));
        self::assertTrue(RoleGate::allows('manager', $rest . '/count', 'POST'));
        self::assertTrue(RoleGate::allows('manager', $rest . '/all', 'POST'));
        self::assertTrue(RoleGate::allows('manager', $rest, 'GET'));
        self::assertFalse(RoleGate::allows('cashier', $rest, 'GET'));
        self::assertFalse(RoleGate::allows('manager', '/settings/counts/wait', 'POST'));
        self::assertFalse(RoleGate::allows('vendor', $rest, 'GET'));
    }
}
