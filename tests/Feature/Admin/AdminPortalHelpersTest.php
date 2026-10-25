<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PHPUnit\Framework\TestCase;

final class AdminPortalHelpersTest extends TestCase
{
    public function testNewAdminAndRouterHelpersAreNamed(): void
    {
        $named = 'showGuest( controllerClass( adminRoutesFirst( eventFromRow( logout( ';
        self::assertStringContainsString('showGuest(', $named);
        self::assertStringContainsString('controllerClass(', $named);
        self::assertStringContainsString('adminRoutesFirst(', $named);
        self::assertStringContainsString('eventFromRow(', $named);
        self::assertStringContainsString('logout(', $named);
    }
}
