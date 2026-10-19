<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dashboard;

use PerfectConsign\Feature\Dashboard\DashboardStore;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DashboardPageTest extends TestCase
{
    public function testRentRowsKeepBlankVendorsAndTheCollectLink(): void
    {
        $html = $this->render([
            $this->rent('booth-blank', null, 'Ada', 100, false),
            $this->rent('booth-noname', 'vendor-noname', null, 100, false),
            $this->rent('booth-ada', 'vendor-ada', 'Ada Lane', 100, false),
            $this->rent('booth-open', 'vendor-open', 'Bea', 250, true),
        ]);

        self::assertStringNotContainsString('/vendors/vendor-noname', $html);
        self::assertStringNotContainsString('href="/demo/vendors/"', $html);
        self::assertStringContainsString('href="/demo/vendors/vendor-ada"', $html);
        self::assertStringNotContainsString('>Charge $1.00<', $html);
        self::assertStringContainsString('>Charge $2.50<', $html);
        self::assertStringContainsString('/rent/collect?vendor=vendor-ada', $html);
        self::assertStringNotContainsString('/rent/collect?booth=booth-ada', $html);
    }

    public function testAFlatDayIsUpAndADropIsDown(): void
    {
        $delta = new ReflectionMethod(DashboardStore::class, 'delta');
        $store = (new ReflectionClass(DashboardStore::class))->newInstanceWithoutConstructor();

        $flat = $delta->invoke($store, 100, 100);
        self::assertIsArray($flat);
        self::assertSame('up', $flat['direction']);
        self::assertStringContainsString('▲ 0.0%', $flat['text']);

        $down = $delta->invoke($store, 50, 100);
        self::assertIsArray($down);
        self::assertSame('down', $down['direction']);
        self::assertStringContainsString('▼ 50.0%', $down['text']);

        $up = $delta->invoke($store, 200, 100);
        self::assertIsArray($up);
        self::assertSame('up', $up['direction']);
        self::assertStringContainsString('▲ 100.0%', $up['text']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function render(array $rows): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Dashboard/dashboard', [
            'slug' => 'demo',
            'displayName' => 'Demo',
            'greeting' => 'Hello',
            'rentMonth' => '2026-10',
            'activeSessionId' => 'sess',
            'actorRole' => 'owner',
            'rentRows' => $rows,
            'picture' => [
                'sales' => ['amount' => '$0.00', 'delta' => null],
                'payables' => ['amount' => '$0.00', 'caption' => 'None'],
                'trend' => [],
                'today_badge' => '$0.00 today',
                'sales_list' => [],
                'occupancy' => ['percent' => 0, 'caption' => 'Empty', 'rows' => []],
                'shelf' => ['value' => '$0.00', 'unsold' => 0],
            ],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @return array{id: string, code: string, vendor_id: ?string, vendor_name: ?string, rate_minor: int, charged: bool, chargeable: bool, journal_id: null, open_minor: int}
     */
    private function rent(string $id, ?string $vendorId, ?string $vendorName, int $rate, bool $chargeable): array
    {
        return [
            'id' => $id,
            'code' => $id,
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'rate_minor' => $rate,
            'charged' => false,
            'chargeable' => $chargeable,
            'journal_id' => null,
            'open_minor' => 0,
        ];
    }
}
