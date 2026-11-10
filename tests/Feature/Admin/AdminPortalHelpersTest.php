<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Feature\Admin\AdminApp;
use PerfectConsign\Feature\Admin\AdminAuditController;
use PerfectConsign\Feature\Admin\AdminSessionController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AdminPortalHelpersTest extends TestCase
{
    public function testAdminAndRouterHelpersExist(): void
    {
        self::assertTrue((new ReflectionClass(AdminApp::class))->hasMethod('showGuest'));
        self::assertTrue((new ReflectionClass(RegisterFeatures::class))->hasMethod('controllerClass'));
        self::assertTrue((new ReflectionClass(AdminAuditController::class))->hasMethod('eventFromRow'));
        self::assertTrue((new ReflectionClass(AdminSessionController::class))->hasMethod('logout'));
    }
}
