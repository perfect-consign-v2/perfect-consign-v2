<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Feature\Inventory\IntakeController;
use PerfectConsign\Shared\Http\ResponseSent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class IntakeControllerTest extends TestCase
{
    public function testBrandConstraintMessagesAreRewritten(): void
    {
        $controller = (new ReflectionClass(IntakeController::class))->newInstanceWithoutConstructor();
        $error = new ReflectionMethod(IntakeController::class, 'brandError');
        self::assertSame('That name is already used.', $error->invoke($controller, 'brands_name_key'));
        self::assertSame('Enter a brand name.', $error->invoke($controller, 'brands_name_chk'));
        self::assertSame('keep this', $error->invoke($controller, 'keep this'));
    }

    public function testAJsonFailureSendsAGenericError(): void
    {
        $controller = (new ReflectionClass(IntakeController::class))->newInstanceWithoutConstructor();
        $send = new ReflectionMethod(IntakeController::class, 'sendJson');
        ob_start();
        try {
            $send->invoke($controller, [fopen('php://memory', 'r')]);
            self::fail('json was sent');
        } catch (ResponseSent $sent) {
            $body = ob_get_clean();
            self::assertSame('json', $sent->getMessage());
            self::assertSame('{"error":"The list could not be sent."}', $body);
        }
    }
}
