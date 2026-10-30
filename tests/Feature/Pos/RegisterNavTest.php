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
        self::assertStringContainsString('/demo/rent"', $empty);
        self::assertStringContainsString('>Collect rent</a>', $empty);
    }

    public function testCollectRentIsHiddenWhenTheStoreIsConsignmentOnly(): void
    {
        $html = $this->nav('', consignmentOnly: true);

        self::assertStringNotContainsString('Collect rent', $html);
        self::assertStringNotContainsString('/demo/rent"', $html);
    }

    public function testCollectRentIsHiddenWhenBoothRentIsOff(): void
    {
        $html = $this->nav('', boothRentals: false);

        self::assertStringNotContainsString('Collect rent', $html);
        self::assertStringNotContainsString('/demo/rent"', $html);
    }

    private function nav(string $lastSale, bool $boothRentals = true, bool $consignmentOnly = false): string
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
            'boothRentals' => $boothRentals,
            'consignmentOnly' => $consignmentOnly,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
