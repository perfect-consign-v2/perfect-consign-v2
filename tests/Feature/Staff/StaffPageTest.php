<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Staff;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class StaffPageTest extends TestCase
{
    public function testABooksLoginShowsThePasswordForm(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Staff/staff', [
            'slug' => 'demo',
            'csrf' => 'token',
            'cashiers' => [],
            'managers' => [],
            'readers' => [[
                'id' => '01900000-0000-7000-8000-0000000000b1',
                'username' => 'books',
                'display_name' => 'Books',
                'role' => 'books',
                'email' => 'books@example.com',
            ]],
            'error' => null,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('People', $html);
        self::assertStringContainsString('books-password', $html);
        self::assertStringContainsString('Save books sign-in', $html);
        self::assertStringContainsString('cannot sell, change tax, pay vendors', $html);
        ob_start();
        $view->render('Feature/Staff/staff', [
            'slug' => 'demo',
            'csrf' => 'token',
            'cashiers' => [],
            'managers' => [],
            'readers' => [],
            'error' => null,
        ]);
        $empty = ob_get_clean();
        self::assertIsString($empty);
        self::assertStringContainsString('No books login yet', $empty);
        self::assertStringContainsString('No managers yet', $empty);
    }

    public function testACashierIsListedWhenTheMallHasOne(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Staff/staff', [
            'slug' => 'demo',
            'csrf' => 'token',
            'cashiers' => [[
                'id' => '01900000-0000-7000-8000-0000000000c1',
                'username' => 'ada',
                'display_name' => 'Ada Lane',
                'role' => 'cashier',
                'email' => 'ada@example.com',
            ]],
            'managers' => [[
                'id' => '01900000-0000-7000-8000-0000000000a1',
                'username' => 'morgan',
                'display_name' => 'Morgan Lead',
                'role' => 'manager',
                'email' => 'morgan@example.com',
            ]],
            'readers' => [],
            'error' => null,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('Ada Lane', $html);
        self::assertStringContainsString('Morgan Lead', $html);
        self::assertStringContainsString('manager-password', $html);
        self::assertStringNotContainsString('No cashiers yet', $html);
        self::assertStringNotContainsString('No managers yet', $html);
    }
}
