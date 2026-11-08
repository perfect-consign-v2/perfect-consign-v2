<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Brand;

use PerfectConsign\Feature\Brand\BrandController;
use PerfectConsign\Feature\Brand\BrandStore;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class BrandControllerTest extends TestCase
{
    public function testEnglishRefusalsPassThrough(): void
    {
        $controller = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        $error = new ReflectionMethod(BrandController::class, 'brandError');
        self::assertSame('That name is already used.', $error->invoke($controller, 'That name is already used.'));
        self::assertSame('Enter a brand name.', $error->invoke($controller, 'Enter a brand name.'));
        self::assertSame('keep this', $error->invoke($controller, 'keep this'));
    }

    public function testConfirmNeedsADeletableId(): void
    {
        $controller = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        $confirm = new ReflectionMethod(BrandController::class, 'confirming');
        $id = '01900000-0000-7000-8000-0000000000b1';
        self::assertSame('', $confirm->invoke($controller, [], 'nope'));
        self::assertSame('', $confirm->invoke($controller, [['id' => $id, 'deletable' => false]], $id));
        self::assertSame($id, $confirm->invoke($controller, [['id' => $id, 'deletable' => true]], strtoupper($id)));
    }

    public function testADraftNameFallsBackToFind(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->once())->method('bound')->willReturn([['name' => 'Found']]);
        $controller = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BrandController::class, 'brands'))->setValue(
            $controller,
            new BrandStore($statements, new CatalogLinks($statements))
        );
        $draft = new ReflectionMethod(BrandController::class, 'draftName');
        self::assertSame(
            'Found',
            $draft->invoke($controller, [], '01900000-0000-7000-8000-0000000000b1')
        );
    }
}
