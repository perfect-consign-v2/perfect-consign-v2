<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Close;

use PerfectConsign\Feature\Close\CloseController;
use PerfectConsign\Feature\Close\CloseStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

final class CloseControllerGapTest extends TestCase
{
    use CapturesPage;

    public function testAnEmptyPeriodClosesWithoutAJournal(): void
    {
        $door = MallDoor::open($this, $this->post());
        $this->capture(function () use ($door): void {
            $this->controller($door)->submit(MallDoor::SLUG);
        });

        self::assertSame('Period closed.', $door->bag['notice'] ?? null);
    }

    public function testMovementPostsAClosingJournal(): void
    {
        $door = MallDoor::open($this, $this->post());
        $door->statements->movement = [
            ['account_id' => MallDoor::ACCOUNT, 'net_minor' => 100],
            ['account_id' => MallDoor::VENDOR, 'net_minor' => -30],
        ];
        $this->capture(function () use ($door): void {
            $this->controller($door)->submit(MallDoor::SLUG);
        });

        self::assertSame('Period closed.', $door->bag['notice'] ?? null);
    }

    private function post(): Request
    {
        return new Request('POST', '/' . MallDoor::SLUG . '/close', [], [
            'csrf' => 'csrf-token',
            'period_id' => MallDoor::RUN,
            'ends_on' => '2026-12-31',
        ]);
    }

    private function controller(MallDoor $door): CloseController
    {
        return new CloseController(
            $door->app,
            $door->ledger,
            new CloseStore($door->pdo, $door->statements),
            $door->mall,
        );
    }
}
