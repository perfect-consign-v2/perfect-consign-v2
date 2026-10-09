<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class SaturdayNavTest extends TestCase
{
    public function testTheMenuUsesSaturdayWords(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/layout', [
            'title' => 'Demo',
            'displayName' => 'Demo Mall',
            'slug' => 'demo',
            'nav' => true,
            'csrf' => 'token',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'path' => '/demo/dashboard',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('Sell', $html);
        self::assertStringContainsString('Pieces', $html);
        self::assertStringContainsString('Close the year', $html);
        self::assertStringNotContainsString('Search items, vendors, booths', $html);
        self::assertStringNotContainsString('Notifications', $html);
    }
}
