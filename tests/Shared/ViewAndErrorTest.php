<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewAndErrorTest extends TestCase
{
    public function testAMissingTemplateIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing template');
        (new View(dirname(__DIR__, 2) . '/src'))->render('Shared/Web/does-not-exist');
    }

    public function testAJournalLinePartialShowsASubledger(): void
    {
        $view = new View(dirname(__DIR__, 2) . '/src');
        ob_start();
        $view->render('Shared/Web/partials/line', [
            'name' => 'line',
            'slot' => 1,
            'accounts' => [['id' => 'a1', 'code' => '1000', 'name' => 'Cash']],
            'subledger' => true,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('line_subledger_type_1', $html);
        ob_start();
        $view->render('Shared/Web/partials/line', [
            'name' => 'line',
            'slot' => 2,
            'accounts' => [],
            'subledger' => false,
        ]);
        $plain = ob_get_clean();
        self::assertIsString($plain);
        self::assertStringNotContainsString('line_subledger_type_2', $plain);
    }

}
