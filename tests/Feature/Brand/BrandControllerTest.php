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
    public function testConstraintMessagesAreRewritten(): void
    {
        $controller = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        $error = new ReflectionMethod(BrandController::class, 'brandError');
        self::assertSame('That name is already used.', $error->invoke($controller, 'brands_name_key'));
        self::assertSame('Enter a brand name.', $error->invoke($controller, 'brands_name_chk'));
        self::assertSame('That brand is in use.', $error->invoke($controller, 'foreign key constraint'));
        self::assertSame('That brand is in use.', $error->invoke($controller, 'is still referenced'));
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
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => 'b1', 'name' => 'Stored']]);
        $store = new BrandStore($statements, new CatalogLinks($statements));
        $controller = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BrandController::class, 'brands'))->setValue($controller, $store);
        $draft = new ReflectionMethod(BrandController::class, 'draftName');
        self::assertSame('Listed', $draft->invoke($controller, [['id' => 'b1', 'name' => 'Listed']], 'b1'));
        self::assertSame('Stored', $draft->invoke($controller, [['id' => 'other', 'name' => 'X']], 'b1'));
        $empty = $this->createStub(Statements::class);
        $empty->method('bound')->willReturn([]);
        $blank = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BrandController::class, 'brands'))->setValue(
            $blank,
            new BrandStore($empty, new CatalogLinks($empty)),
        );
        self::assertSame('', $draft->invoke($blank, [], 'b1'));
    }

    public function testReferencedBrandsAreNotDeletable(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['relation' => 'items', 'column_name' => 'brand_id']]);
        $statements->method('rows')->willReturn([['id' => 'held']]);
        $controller = (new ReflectionClass(BrandController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BrandController::class, 'brands'))->setValue(
            $controller,
            new BrandStore($statements, new CatalogLinks($statements)),
        );
        $marked = (new ReflectionMethod(BrandController::class, 'withUse'))->invoke($controller, [
            ['id' => 'held', 'name' => 'A'],
            ['id' => 'free', 'name' => 'B'],
        ]);
        self::assertFalse($marked[0]['deletable']);
        self::assertTrue($marked[1]['deletable']);
    }
}
