<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dashboard;

use PerfectConsign\Feature\Dashboard\DashboardStore;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DashboardPictureTest extends TestCase
{
    public function testASalesChangePointsUpOrDown(): void
    {
        $store = (new ReflectionClass(DashboardStore::class))->newInstanceWithoutConstructor();
        $delta = new ReflectionMethod(DashboardStore::class, 'delta');
        $up = $delta->invoke($store, 200, 100);
        $down = $delta->invoke($store, 50, 100);
        self::assertIsArray($up);
        self::assertSame('up', $up['direction']);
        self::assertIsArray($down);
        self::assertSame('down', $down['direction']);
        self::assertNull($delta->invoke($store, 10, 0));
    }

    public function testTheDashboardShowsADeltaAndABooksCollectBlank(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $picture = [
            'sales' => ['amount' => '$10.00', 'delta' => ['direction' => 'up', 'text' => '▲ 10.0% vs yesterday']],
            'payables' => ['amount' => '$0.00', 'caption' => 'owed'],
            'trend' => [],
            'today_badge' => '$10.00 today',
            'sales_list' => [],
            'occupancy' => ['percent' => 0, 'caption' => '', 'rows' => []],
            'shelf' => ['value' => '$0.00', 'unsold' => 0],
        ];
        $row = [
            'id' => '01900000-0000-7000-8000-0000000000b1',
            'code' => 'A1',
            'vendor_id' => null,
            'vendor_name' => null,
            'rate_minor' => 1000,
            'charged' => false,
            'chargeable' => false,
            'journal_id' => null,
            'open_minor' => 0,
        ];
        ob_start();
        $view->render('Feature/Dashboard/dashboard', [
            'slug' => 'demo',
            'displayName' => 'Demo',
            'greeting' => 'Hello',
            'picture' => $picture,
            'rentRows' => [$row],
            'rentMonth' => '2026-10',
            'activeSessionId' => 'sess',
            'actorRole' => 'books',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('▲ 10.0% vs yesterday', $html);
        self::assertStringContainsString('stat-delta up', $html);
        self::assertStringNotContainsString('Collect', $html);
        $row['chargeable'] = true;
        $row['vendor_id'] = '01900000-0000-7000-8000-0000000000aa';
        $row['vendor_name'] = 'Ada';
        ob_start();
        $view->render('Feature/Dashboard/dashboard', [
            'slug' => 'demo',
            'displayName' => 'Demo',
            'greeting' => 'Hello',
            'picture' => $picture,
            'rentRows' => [$row],
            'rentMonth' => '2026-10',
            'activeSessionId' => '',
            'actorRole' => 'owner',
        ]);
        $owner = ob_get_clean();
        self::assertIsString($owner);
        self::assertStringContainsString('Open a register to collect', $owner);
        $row['rate_minor'] = null;
        ob_start();
        $view->render('Feature/Dashboard/dashboard', [
            'slug' => 'demo',
            'displayName' => 'Demo',
            'greeting' => 'Hello',
            'picture' => $picture,
            'rentRows' => [$row],
            'rentMonth' => '2026-10',
            'activeSessionId' => 'sess',
            'actorRole' => 'owner',
        ]);
        $noRate = ob_get_clean();
        self::assertIsString($noRate);
        self::assertStringContainsString('No rate', $noRate);
    }
}
