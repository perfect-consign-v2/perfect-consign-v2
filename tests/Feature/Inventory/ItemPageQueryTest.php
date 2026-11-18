<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Feature\Inventory\ItemPageQuery;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class ItemPageQueryTest extends TestCase
{
    public function testALengthOutsideTheMenuIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That page size is not allowed.');
        ItemPageQuery::from($this->request(['length' => '15']), '', '', '2026-06-15', ['code']);
    }

    public function testAnUnknownSortIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be sorted.');
        ItemPageQuery::from($this->request([
            'length' => '10',
            'order' => [['name' => 'drop table', 'dir' => 'asc']],
        ]), '', '', '2026-06-15', ['code']);
    }

    public function testABadDirectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be sorted.');
        ItemPageQuery::from($this->request([
            'length' => '25',
            'order' => [['column' => '1', 'dir' => 'sideways']],
            'columns' => [1 => ['name' => 'code', 'search' => ['value' => '']]],
        ]), '', '', '2026-06-15', ['code']);
    }

    public function testAnEmptyLengthIsTheFirstPageOfTen(): void
    {
        $query = ItemPageQuery::from($this->request(['draw' => '4']), 'available', '', '2026-06-15', ['code', 'price']);

        self::assertSame(10, $query->window->length);
        self::assertSame(0, $query->window->start);
        self::assertSame(4, $query->window->draw);
        self::assertSame('code', $query->sort);
        self::assertSame('asc', $query->direction);
        self::assertSame('available', $query->filter->stock);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(array $query): Request
    {
        return new Request('GET', '/demo/inventory/rows', $query, []);
    }
}
