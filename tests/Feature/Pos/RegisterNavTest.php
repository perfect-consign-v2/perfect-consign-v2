<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class RegisterNavTest extends TestCase
{
    public function testTheLastSaleLinkAppearsOnlyAfterASale(): void
    {
        $empty = $this->nav('');
        $sold = $this->nav('S-9');

        self::assertStringNotContainsString('Last sale', $empty);
        self::assertStringContainsString('/demo/pos/receipt/S-9?print=1', $sold);
        self::assertStringContainsString('>Last sale</a>', $sold);
    }

    private function nav(string $lastSale): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/register-nav', [
            'slug' => 'demo',
            'displayName' => 'Demo Mall',
            'cart' => ['parked' => []],
            'lastSale' => $lastSale,
            'giftNumber' => '',
            'giftBalance' => null,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
