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
        self::assertStringContainsString('books-password', $html);
        self::assertStringContainsString('Save books sign-in', $html);
        ob_start();
        $view->render('Feature/Staff/staff', [
            'slug' => 'demo',
            'csrf' => 'token',
            'cashiers' => [],
            'readers' => [],
            'error' => null,
        ]);
        $empty = ob_get_clean();
        self::assertIsString($empty);
        self::assertStringContainsString('No books login yet', $empty);
    }
}
