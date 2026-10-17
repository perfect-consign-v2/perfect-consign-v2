<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use InvalidArgumentException;
use PerfectConsign\Feature\Mall\ReceiptFooter;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class ReceiptFooterTest extends TestCase
{
    public function testAFooterKeepsLineBreaksAndDropsTheEdges(): void
    {
        self::assertSame('', ReceiptFooter::text("  \r\n"));
        self::assertSame("All sales final\nThank you", ReceiptFooter::text("  All sales final\r\nThank you\r"));
        self::assertSame(str_repeat('é', 400), ReceiptFooter::text(str_repeat('é', 400)));
        self::assertSame(str_repeat('x', 400), ReceiptFooter::text(str_repeat('x', 400)));
    }

    public function testAFooterLongerThanFourHundredCharactersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('receipt footer is 400 characters or less');
        ReceiptFooter::text(str_repeat('x', 401));
    }

    public function testAnEmptyFooterPrintsNothingAndASetFooterPrintsAsTyped(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/partials/slip-foot', ['receiptFooter' => '']);
        $empty = ob_get_clean();
        ob_start();
        $view->render('Shared/Web/partials/slip-foot', ['receiptFooter' => "All sales final\n<script>"]);
        $set = ob_get_clean();

        self::assertIsString($empty);
        self::assertStringNotContainsString('slip-thanks', $empty);
        self::assertIsString($set);
        self::assertStringContainsString("All sales final\n&lt;script&gt;", $set);
        self::assertStringNotContainsString('<script>', $set);
    }
}
