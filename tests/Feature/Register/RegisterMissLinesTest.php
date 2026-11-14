<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Register;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterController;
use PerfectConsign\Feature\Register\RegisterHub;
use PerfectConsign\Feature\Register\RegisterSession;
use PerfectConsign\Feature\Register\RegisterShiftController;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PHPUnit\Framework\TestCase;

final class RegisterMissLinesTest extends TestCase
{
    use CapturesPage;

    public function testAMissingRegisterIsNotFound(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/registers/' . MallDoor::DRAWER . '/edit', [], []));
        $controller = $this->controller($door, new RoutedStatements($this->createStub(\PDO::class)));
        $this->capture(function () use ($controller): void {
            $controller->edit(MallDoor::SLUG, MallDoor::DRAWER);
        });
        $this->capture(function () use ($controller): void {
            $controller->shifts(MallDoor::SLUG, MallDoor::DRAWER);
        });
        $this->capture(function () use ($controller): void {
            $controller->tape(MallDoor::SLUG, MallDoor::DRAWER);
        });

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/registers/' . MallDoor::DRAWER, [], [
            'csrf' => 'csrf-token',
            'name' => 'Front',
        ]));
        $this->capture(function () use ($door): void {
            $this->controller($door, new RoutedStatements($this->createStub(\PDO::class)))->rename(MallDoor::SLUG, MallDoor::DRAWER);
        });
        $this->capture(function () use ($door): void {
            $this->controller($door, new RoutedStatements($this->createStub(\PDO::class)))->delete(MallDoor::SLUG, MallDoor::DRAWER);
        });
        $this->capture(function () use ($door): void {
            $this->controller($door, new RoutedStatements($this->createStub(\PDO::class)))->use(MallDoor::SLUG, MallDoor::DRAWER);
        });
    }

    public function testRenameAndDeleteRefusalsRepaintTheHub(): void
    {
        $statements = $this->found();
        $statements->affectedError = new DatabaseRefusal('refused');
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/registers/' . MallDoor::DRAWER, [], [
            'csrf' => 'csrf-token',
            'name' => 'Front',
        ]));
        $html = $this->capture(function () use ($door, $statements): void {
            $this->controller($door, $statements)->rename(MallDoor::SLUG, MallDoor::DRAWER);
        });
        self::assertStringContainsString('refused', $html);

        $statements = $this->found();
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/registers/' . MallDoor::DRAWER . '/delete', [], [
            'csrf' => 'csrf-token',
        ]));
        $html = $this->capture(function () use ($door, $statements): void {
            $this->controller($door, $statements)->delete(MallDoor::SLUG, MallDoor::DRAWER);
        });
        self::assertStringContainsString('not in this mall', $html);
    }

    public function testCloseRefusesAMissingRegisterAndABadCount(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/registers/' . MallDoor::DRAWER . '/close', [], [
            'csrf' => 'csrf-token',
        ]));
        $this->capture(function () use ($door): void {
            $this->shifts($door, new RoutedStatements($this->createStub(\PDO::class)))->close(MallDoor::SLUG, MallDoor::DRAWER);
        });

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/registers/' . MallDoor::DRAWER . '/close', [], [
            'csrf' => 'csrf-token',
            'counted_cash' => '0.00',
        ]));
        $html = $this->capture(function () use ($door): void {
            $this->shifts($door, $this->found())->close(MallDoor::SLUG, MallDoor::DRAWER);
        });
        self::assertNotSame('', $html);
    }

    public function testAnUnknownDrawerSessionIsRefused(): void
    {
        $activity = new RegisterActivity(new RoutedStatements($this->createStub(\PDO::class)), new MallClock(MallDoor::open($this, new Request('GET', '/', [], []))->mall));
        $this->expectException(DatabaseRefusal::class);
        $activity->drawer(MallDoor::DRAWER);
    }

    public function testAStaleOpenRegisterMustBeClosed(): void
    {
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        $html = '';
        $this->capture(function () use ($view, &$html): void {
            ob_start();
            $view->render('Feature/Register/registers', [
                'slug' => MallDoor::SLUG,
                'csrf' => 'csrf-token',
                'cashier' => 'Ada',
                'today' => '2026-11-12',
                'modal' => 'close-' . MallDoor::DRAWER,
                'registerId' => MallDoor::DRAWER,
                'confirmVariance' => false,
                'draft' => [],
                'denominations' => DenominationCount::LABELS,
                'leftOpen' => [['id' => MallDoor::DRAWER, 'name' => 'Front', 'opened' => 'Nov 1']],
                'registers' => [[
                    'id' => MallDoor::DRAWER,
                    'name' => 'Front',
                    'open' => true,
                    'tenders' => ['card' => 0, 'checks' => 0],
                    'drops' => [],
                    'cashier' => 'Ada',
                    'opened_at' => 'Nov 1',
                    'opening_cash' => '0.00',
                    'expected_cash' => '0.00',
                    'unsynced_cash' => '0.00',
                    'refused_cash' => '0.00',
                    'session_id' => MallDoor::JOURNAL,
                    'shifts' => 1,
                    'expected_minor' => 0,
                    'open_ticket' => false,
                ]],
            ]);
            $html = (string) ob_get_clean();
        });
        self::assertStringContainsString('close-cancel', $html);
        self::assertStringContainsString('data-lock', $html);
    }

    private function found(): RoutedStatements
    {
        $statements = new RoutedStatements($this->createStub(\PDO::class));
        $statements->boundRoutes[] = ['match' => 'SELECT id, name FROM registers', 'rows' => [[
            'id' => MallDoor::DRAWER,
            'name' => 'Front',
        ]]];

        return $statements;
    }

    private function controller(MallDoor $door, RoutedStatements $statements): RegisterController
    {
        $clock = new MallClock($door->mall);
        $store = new RegisterStore($statements, $clock, new RegisterActivity($statements, $clock));

        return new RegisterController(
            $door->app,
            $store,
            new RegisterActivity($statements, $clock),
            $door->register,
            new RegisterHub($door->app, $store, $door->register),
        );
    }

    private function shifts(MallDoor $door, RoutedStatements $statements): RegisterShiftController
    {
        $clock = new MallClock($door->mall);
        $activity = new RegisterActivity($statements, $clock);
        $store = new RegisterStore($statements, $clock, $activity);

        return new RegisterShiftController(
            $door->app,
            $door->mall,
            $store,
            new RegisterSession($statements, $door->ledger, $clock, $activity),
            $door->register,
            new RegisterHub($door->app, $store, $door->register),
        );
    }
}
