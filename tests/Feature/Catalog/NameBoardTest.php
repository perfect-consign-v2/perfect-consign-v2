<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Catalog;

use PerfectConsign\Feature\Catalog\NameBoard;
use PHPUnit\Framework\TestCase;

final class NameBoardTest extends TestCase
{
    public function testMergeNamesTheOnlyOtherRow(): void
    {
        $rows = [
            ['id' => '01900000-0000-7000-8000-0000000000b1', 'name' => 'Held', 'use_count' => 3],
            ['id' => '01900000-0000-7000-8000-0000000000b2', 'name' => "Levi's", 'use_count' => 0],
        ];
        $view = NameBoard::merge($rows, '01900000-0000-7000-8000-0000000000B1');

        self::assertSame('01900000-0000-7000-8000-0000000000b1', $view['id']);
        self::assertSame("3 items will show Levi's. Held will be removed.", $view['sentence']);
        self::assertSame(["Levi's"], array_column($view['choices'], 'name'));
    }

    public function testMergeLeavesTheKeeperUnnamedWhenSeveralRemain(): void
    {
        $rows = [
            ['id' => '01900000-0000-7000-8000-0000000000b1', 'name' => 'Held', 'use_count' => 3],
            ['id' => '01900000-0000-7000-8000-0000000000b2', 'name' => 'Red', 'use_count' => 0],
            ['id' => '01900000-0000-7000-8000-0000000000b3', 'name' => 'Blue', 'use_count' => 0],
        ];
        $view = NameBoard::merge($rows, '01900000-0000-7000-8000-0000000000b1');

        self::assertSame('3 items will show the name you choose. Held will be removed.', $view['sentence']);
        self::assertSame(['Red', 'Blue'], array_column($view['choices'], 'name'));
    }

    public function testANameInUseIsNotDeletable(): void
    {
        $id = '01900000-0000-7000-8000-0000000000b1';
        $marked = NameBoard::mark(
            [['id' => $id, 'name' => 'Red', 'active' => 't']],
            [$id => true],
            [$id => 2],
        );

        self::assertTrue($marked[0]['active']);
        self::assertFalse($marked[0]['deletable']);
        self::assertSame(2, $marked[0]['use_count']);
        self::assertSame('', NameBoard::confirming($marked, $id));
    }

    public function testConfirmAcceptsOnlyADeletableId(): void
    {
        $id = '01900000-0000-7000-8000-0000000000b1';

        self::assertSame('', NameBoard::confirming([], 'nope'));
        self::assertSame('', NameBoard::confirming([['id' => $id, 'deletable' => false]], $id));
        self::assertSame($id, NameBoard::confirming([['id' => $id, 'deletable' => true]], strtoupper($id)));
    }
}
