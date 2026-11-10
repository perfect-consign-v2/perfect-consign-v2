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
                'can_sign_in' => true,
            ]],
            'owners' => [],
            'actorAccount' => '',
            'error' => null,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('Staff', $html);
        self::assertStringContainsString('Add a person', $html);
        self::assertStringContainsString('name="role"', $html);
        self::assertStringContainsString('value="owner"', $html);
        self::assertStringContainsString('books-password', $html);
        self::assertStringContainsString('Save books sign-in', $html);
        self::assertStringContainsString('/staff/01900000-0000-7000-8000-0000000000b1/suspend', $html);
        self::assertStringContainsString('/staff/01900000-0000-7000-8000-0000000000b1/role', $html);
        self::assertStringContainsString('Owners have full mall access', $html);
        ob_start();
        $view->render('Feature/Staff/staff', [
            'slug' => 'demo',
            'csrf' => 'token',
            'cashiers' => [],
            'managers' => [],
            'readers' => [],
            'owners' => [],
            'actorAccount' => '',
            'error' => null,
        ]);
        $empty = ob_get_clean();
        self::assertIsString($empty);
        self::assertStringContainsString('No books login yet', $empty);
        self::assertStringContainsString('No managers yet', $empty);
        self::assertStringContainsString('No owners on this mall', $empty);
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
                'can_sign_in' => false,
            ]],
            'managers' => [[
                'id' => '01900000-0000-7000-8000-0000000000a1',
                'username' => 'morgan',
                'display_name' => 'Morgan Lead',
                'role' => 'manager',
                'email' => 'morgan@example.com',
                'can_sign_in' => true,
            ]],
            'readers' => [],
            'owners' => [[
                'id' => '01900000-0000-7000-8000-0000000000o1',
                'username' => 'owner',
                'email' => 'owner@example.com',
                'can_sign_in' => true,
            ], [
                'id' => '01900000-0000-7000-8000-0000000000o2',
                'username' => 'partner',
                'email' => 'partner@example.com',
                'can_sign_in' => true,
            ]],
            'actorAccount' => '01900000-0000-7000-8000-0000000000o1',
            'error' => null,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('Ada Lane', $html);
        self::assertStringContainsString('Suspended', $html);
        self::assertStringContainsString('/staff/01900000-0000-7000-8000-0000000000c1/restore', $html);
        self::assertStringContainsString('Morgan Lead', $html);
        self::assertStringContainsString('manager-password', $html);
        self::assertStringContainsString('partner', $html);
        self::assertStringContainsString('owners/01900000-0000-7000-8000-0000000000o2/remove', $html);
        self::assertStringContainsString('owners/01900000-0000-7000-8000-0000000000o2/suspend', $html);
        self::assertStringNotContainsString('owners/01900000-0000-7000-8000-0000000000o1/remove', $html);
        self::assertStringNotContainsString('No cashiers yet', $html);
        self::assertStringNotContainsString('No managers yet', $html);
    }
}
