<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Payout\CheckWords;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutOnce;
use PerfectConsign\Feature\Payout\PayoutReverser;
use PerfectConsign\Feature\Payout\PayoutRunChecks;
use PerfectConsign\Feature\Payout\PayoutRunController;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunSheets;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\PrintedChecks;
use PerfectConsign\Feature\Payout\RentShortfall;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

final class PayoutRunGapTest extends TestCase
{
    use CapturesPage;

    public function testAVoidedCheckLeavesTheReport(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/payouts/report', ['run' => MallDoor::RUN], []));
        $door->statements->voidedChecks = true;
        $this->capture(function () use ($door): void {
            $this->controller($door)->report(MallDoor::SLUG);
        });

        self::assertSame('That check was voided.', $door->bag['notice'] ?? null);
    }

    public function testUndoStopsWhenTheCheckWasVoided(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/payouts/undo', [
            'run_id' => MallDoor::RUN,
            'reason' => 'wrong month',
        ]));
        $door->statements->voidedChecks = true;
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->undo(MallDoor::SLUG);
        });

        self::assertStringContainsString('That check was voided.', $html);
    }

    public function testUndoRedirectsAfterTheReversalPosts(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/payouts/undo', [
            'run_id' => MallDoor::RUN,
            'reason' => 'wrong month',
        ]));
        $this->capture(function () use ($door): void {
            $this->controller($door)->undo(MallDoor::SLUG);
        });

        self::assertSame('Payout undone.', $door->bag['notice'] ?? null);
    }

    public function testCloseRefusesWhenTheMonthIsAlreadyPaid(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/payouts/close', ['tender' => 'check']));
        $today = $door->mall->today();
        $window = (new PayoutWindow())->window($today, 1);
        $door->statements->paidRun = [
            'id' => MallDoor::RUN,
            'run_no' => 4,
            'status' => 'paid',
            'tender' => 'check',
            'sales_from' => $window['from'],
            'sales_to' => $window['to'],
            'rent_month' => $window['rent'],
            'reversed_at' => '',
            'vendor_name' => 'Ada',
            'check_minor' => 0,
            'check_void' => '',
            'reverses_run_id' => '',
        ];
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->close(MallDoor::SLUG);
        });

        self::assertStringContainsString('This month is already paid.', $html);
    }

    public function testClosePaysByCheckAndRedirects(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/payouts/close', ['tender' => 'check']));
        $door->statements->invokeTransaction = false;
        $this->capture(function () use ($door): void {
            $this->controller($door)->close(MallDoor::SLUG);
        });

        self::assertIsString($door->bag['notice'] ?? null);
        self::assertStringContainsString('is paid.', (string) $door->bag['notice']);
    }

    public function testCloseShowsTheRefusalWhenPayingIsRefused(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/payouts/close', ['tender' => 'check']));
        $door->statements->transactionRefuses = true;
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->close(MallDoor::SLUG);
        });

        self::assertStringContainsString('refused', $html);
    }

    public function testACheckPostWithoutARunShowsTheMissingPayout(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/payouts/checks', []));
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->checks(MallDoor::SLUG);
        });

        self::assertStringContainsString('That payout is missing.', $html);
    }

    /**
     * @param array<string, string> $post
     */
    private function post(string $path, array $post): Request
    {
        return new Request('POST', $path, [], ['csrf' => 'csrf-token'] + $post);
    }

    private function controller(MallDoor $door): PayoutRunController
    {
        $runs = new PayoutRunStore($door->statements, new OccupiedPack($door->statements));
        $sheets = new PayoutRunSheets($door->statements);
        $window = new PayoutWindow();
        $payouts = new PayoutStore($door->pdo, $door->statements, $runs, $window);
        $lines = new PayoutRunLines($door->statements);
        $audit = new JournalAudit($door->actor, new AuditLog($door->statements));
        $clawback = new VendorClawback($door->ledger, $door->statements);
        $settler = new PayoutSettler($door->ledger, $payouts, $runs, $lines, $audit, $clawback);
        $disburser = new PayoutDisburser($door->ledger, $payouts, $runs, $lines, new PayoutRunChecks($door->statements), $audit);
        $ceiling = new SaleCeiling($door->statements, $door->actor, new AuditLog($door->statements), $door->app->request());
        $once = new PayoutOnce(
            $runs,
            $sheets,
            $settler,
            $disburser,
            $door->statements,
            new CheckWords(),
            new RentShortfall($door->statements),
            $ceiling,
        );

        return new PayoutRunController(
            $door->app,
            $door->mall,
            $runs,
            $window,
            $once,
            new PayoutReverser($door->ledger, $door->statements),
            $door->register,
            new \PerfectConsign\Feature\Mall\MallClock($door->mall),
            new PrintedChecks($door->app, $runs, $sheets, $once),
        );
    }
}
