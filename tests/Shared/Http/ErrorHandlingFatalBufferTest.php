<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\ErrorHandling;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ErrorHandlingFatalBufferTest extends TestCase
{
    public function testRegisterShutdownDrainsOutputBuffersBeforeEcho(): void
    {
        $source = file_get_contents((new ReflectionClass(ErrorHandling::class))->getFileName() ?: '');
        self::assertIsString($source);
        self::assertStringContainsString('while (ob_get_level() > $bufferFloor)', $source);
        self::assertStringContainsString('ob_end_clean()', $source);
        $drain = strpos($source, 'while (ob_get_level() > $bufferFloor)');
        $echo = strpos($source, "echo 'The page could not be shown.'");
        self::assertNotFalse($drain);
        self::assertNotFalse($echo);
        self::assertLessThan($echo, $drain);
    }
}
