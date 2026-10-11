<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class LayawaySlipPageTest extends TestCase
{
    public function testAnOpenSlipKeepsTheLayawayLink(): void
    {
        $html = $this->render(false);

        self::assertStringContainsString('>Layaway<', $html);
        self::assertStringContainsString('Layaways', $html);
        self::assertStringContainsString('Items stay here until the balance is paid', $html);
        self::assertStringContainsString('Cash', $html);
    }

    public function testACancelledSlipDropsTheLayawayLink(): void
    {
        $html = $this->render(true);

        self::assertStringContainsString('Layaway cancelled', $html);
        self::assertStringNotContainsString('Layaways', $html);
        self::assertStringContainsString('Deposits refunded. Items are free.', $html);
        self::assertStringContainsString('>None<', $html);
    }

    private function render(bool $cancelled): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/layaway-slip', [
            'slug' => 'webprobe',
            'displayName' => 'Web Probe',
            'slip' => [
                'ticket_id' => '00000000-0000-7000-8000-000000000001',
                'customer' => $cancelled ? '' : 'Ada',
                'cancelled' => $cancelled,
                'lines' => [
                    ['description' => 'Wool coat', 'code' => 'BC-1', 'quantity' => 1, 'amount' => 1000],
                ],
                'due' => 1000,
                'deposits' => 200,
                'balance' => 800,
                'payments' => [
                    ['posted_on' => '2026-10-01', 'tender' => 'Cash', 'amount' => 200, 'kind' => 'Deposit'],
                ],
            ],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
