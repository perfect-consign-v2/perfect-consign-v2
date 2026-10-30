<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class ManagerMenuTest extends TestCase
{
    public function testAManagerMenuHasFloorAndMoneyReadWithoutSetup(): void
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
            'actorRole' => 'manager',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('Manager', $html);
        self::assertStringContainsString('Sell', $html);
        self::assertStringContainsString('Rent', $html);
        self::assertStringContainsString('Register', $html);
        self::assertStringContainsString('Inventory', $html);
        self::assertStringContainsString('Vendors', $html);
        self::assertStringContainsString('This month', $html);
        self::assertStringContainsString('View payouts', $html);
        self::assertStringContainsString('Reports', $html);
        self::assertStringNotContainsString('Settings', $html);
        self::assertStringNotContainsString('>People<', $html);
        self::assertStringNotContainsString('Close the year', $html);
        self::assertStringNotContainsString('Pay vendors', $html);
    }

    public function testABooksMenuSaysViewPayoutsNotPay(): void
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
            'path' => '/demo/sales',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
            'actorRole' => 'books',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('View payouts', $html);
        self::assertStringNotContainsString('Pay vendors', $html);
    }
}
