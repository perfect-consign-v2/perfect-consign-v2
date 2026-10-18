<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Feature\Inventory\ItemPhotoController;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionProperty;

final class ItemPhotoControllerTest extends TestCase
{
    public function testAStoreFailureIsLoggedAndNotKept(): void
    {
        $log = new class extends AbstractLogger {
            public string $message = '';

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->message = (string) $message;
            }
        };
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(static function (): void {
            throw new \RuntimeException('disk');
        });
        $photos = (new ReflectionClass(ItemPhotoController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ItemPhotoController::class, 'statements'))->setValue($photos, $statements);
        (new ReflectionProperty(ItemPhotoController::class, 'logger'))->setValue($photos, $log);

        self::assertFalse($photos->keep('00000000-0000-7000-8000-0000000000i1', 'ff'));
        self::assertSame('item photo was not stored', $log->message);
    }
}
