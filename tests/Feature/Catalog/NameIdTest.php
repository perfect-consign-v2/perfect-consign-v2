<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Catalog;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class NameIdTest extends TestCase
{
    public function testABrandIdMustBeANonEmptyString(): void
    {
        $catalog = new IntakeCatalog($this->statements(4));
        self::assertNull($catalog->brandId('Ada'));
        self::assertNull((new IntakeCatalog($this->statements('')))->brandId('Ada'));
        self::assertSame('brand-1', (new IntakeCatalog($this->statements('brand-1')))->brandId('Ada'));
    }

    public function testACatalogIdMustBeANonEmptyString(): void
    {
        $list = NameList::fromPath('colors');
        self::assertInstanceOf(NameList::class, $list);
        $store = new NameListStore($this->statements(4), new CatalogLinks($this->statements(4)));
        self::assertNull($store->idForName($list, 'Red'));
        self::assertNull((new NameListStore($this->statements(''), new CatalogLinks($this->statements(''))))->idForName($list, 'Red'));
        self::assertSame('color-1', (new NameListStore($this->statements('color-1'), new CatalogLinks($this->statements('color-1'))))->idForName($list, 'Red'));
    }

    private function statements(mixed $id): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => $id]]);

        return $statements;
    }
}
