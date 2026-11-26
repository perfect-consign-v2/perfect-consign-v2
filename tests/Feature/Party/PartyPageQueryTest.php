<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Inventory\ItemPageWindow;
use PerfectConsign\Feature\Party\PartyPageQuery;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class PartyPageQueryTest extends TestCase
{
    private const array COLUMNS = [
        ['name' => 'name', 'search' => ['value' => ' Wynn ']],
        ['name' => 'kind', 'search' => ['value' => '']],
        ['name' => 'roles', 'search' => ['value' => 'Wait']],
        ['name' => 'drop table', 'search' => ['value' => 'x']],
        'not a column',
    ];

    public function testAnEmptyAskIsTheFirstPageByName(): void
    {
        $query = PartyPageQuery::from($this->request([]));

        self::assertSame('', $query->search);
        self::assertSame([], $query->columns);
        self::assertSame('name', $query->sort);
        self::assertSame('asc', $query->direction);
        self::assertSame(0, $query->window->start);
        self::assertSame(10, $query->window->length);
        self::assertSame(ItemPageWindow::PAGE, $query->window->mode);
    }

    public function testTheTableAskIsReadFromTheDataTablesFields(): void
    {
        $query = PartyPageQuery::from($this->request([
            'draw' => '4',
            'start' => '20',
            'length' => '25',
            'search' => ['value' => ' Pat '],
            'order' => [['column' => '2', 'dir' => 'DESC']],
            'columns' => self::COLUMNS,
        ]));

        self::assertSame('pat', $query->search);
        self::assertSame(['name' => 'wynn', 'roles' => 'wait'], $query->columns);
        self::assertSame('roles', $query->sort);
        self::assertSame('desc', $query->direction);
        self::assertSame(20, $query->window->start);
        self::assertSame(25, $query->window->length);
        self::assertSame(4, $query->window->draw);
    }

    public function testAnExportAsksForEveryMatchingParty(): void
    {
        $query = PartyPageQuery::from($this->request(['export' => '1', 'start' => '30']));

        self::assertSame(ItemPageWindow::ALL, $query->window->mode);
        self::assertSame(0, $query->window->start);
    }

    public function testAnOrderWithoutANamedColumnSortsByName(): void
    {
        $missing = PartyPageQuery::from($this->request(['order' => [['column' => '9', 'dir' => 'desc']]]));
        self::assertSame('name', $missing->sort);
        self::assertSame('desc', $missing->direction);

        $odd = PartyPageQuery::from($this->request([
            'order' => [['column' => ['0'], 'dir' => 'asc']],
            'columns' => self::COLUMNS,
        ]));
        self::assertSame('name', $odd->sort);

        $unnamed = PartyPageQuery::from($this->request([
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [['name' => ['kind']]],
        ]));
        self::assertSame('name', $unnamed->sort);

        $loose = PartyPageQuery::from($this->request(['order' => 'name', 'search' => ['value' => ['pat']]]));
        self::assertSame('name', $loose->sort);
        self::assertSame('', $loose->search);
    }

    public function testAnUnknownSortIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be sorted.');
        PartyPageQuery::from($this->request([
            'order' => [['column' => '3', 'dir' => 'asc']],
            'columns' => self::COLUMNS,
        ]));
    }

    public function testABadDirectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be sorted.');
        PartyPageQuery::from($this->request(['order' => [['column' => '0', 'dir' => ['asc']]]]));
    }

    public function testATooLongSearchIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That search is too long.');
        PartyPageQuery::from($this->request(['search' => ['value' => str_repeat('a', PartyPageQuery::NEEDLE + 1)]]));
    }

    public function testASearchOfTheLongestAllowedLengthIsKept(): void
    {
        $needle = str_repeat('a', PartyPageQuery::NEEDLE);

        self::assertSame($needle, PartyPageQuery::from($this->request(['search' => ['value' => $needle]]))->search);
    }

    public function testAPageSizeOutsideTheMenuIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That page size is not allowed.');
        PartyPageQuery::from($this->request(['length' => '7']));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(array $query): Request
    {
        return new Request('GET', '/demo/parties/rows', $query, []);
    }
}
