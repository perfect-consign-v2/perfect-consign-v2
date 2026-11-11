<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PHPUnit\Framework\TestCase;

final class RegisterSwapScriptTest extends TestCase
{
    public function testReceiptPostsAreNotRegisterSwapped(): void
    {
        $script = dirname(__DIR__, 3) . '/public/assets/js/register-swap.js';
        self::assertFileExists($script);
        $source = file_get_contents($script);
        self::assertIsString($source);
        self::assertStringContainsString("sale + '/receipt/'", $source);
        self::assertStringContainsString('takeFlash', $source);
        self::assertStringContainsString('return false;', $source);
        self::assertStringContainsString("cashiers never see \"Receipt emailed.\"", $source);
    }
}
