<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class CashierMenuTest extends TestCase
{
    public function testACashierMenuIsSellRentAndRegister(): void
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
            'path' => '/demo/pos',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
            'actorRole' => 'cashier',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('Sell', $html);
        self::assertStringContainsString('Rent', $html);
        self::assertStringContainsString('Register', $html);
        self::assertStringContainsString('Cashier', $html);
        self::assertStringNotContainsString('Close the year', $html);
        self::assertStringNotContainsString('Settings', $html);
        self::assertStringNotContainsString('Pay vendors', $html);
    }
}
