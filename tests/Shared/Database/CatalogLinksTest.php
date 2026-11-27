<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Database;

use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class CatalogLinksTest extends TestCase
{
    public function testABrokenLinkIsSkipped(): void
    {
        $queries = 0;
        $links = new CatalogLinks($this->statements([
            ['relation' => 1, 'column_name' => 'id'],
            ['relation' => 'public.items', 'column_name' => 2],
            ['relation' => '', 'column_name' => 'id'],
            ['relation' => 'public.items', 'column_name' => ''],
        ], static function () use (&$queries): array {
            $queries++;

            return [['id' => 'leaked']];
        }));

        self::assertSame([], $links->referenced('brands'));
        self::assertSame(0, $queries);
    }

    public function testABlankIdIsNotHeld(): void
    {
        $links = new CatalogLinks($this->statements(
            [['relation' => 'public.items', 'column_name' => 'brand_id']],
            static fn (): array => [['id' => ''], ['id' => 'kept'], ['id' => 4]],
        ));

        self::assertSame(['kept' => true], $links->referenced('brands'));
    }

    public function testItemCountsSkipOtherTablesAndBlankIds(): void
    {
        // Only items count as use. Any other table that names a brand is not an item.
        $links = new CatalogLinks($this->statements(
            [
                ['relation' => 'public.other_links', 'column_name' => 'brand_id'],
                ['relation' => 'public.items', 'column_name' => 'brand_id'],
            ],
            static fn (): array => [
                ['id' => 'kept', 'used' => '3'],
                ['id' => '', 'used' => '1'],
                ['id' => 'two', 'used' => 2],
                ['id' => 'bad', 'used' => 'no'],
            ],
        ));

        self::assertSame(['kept' => 3, 'two' => 2], $links->itemCounts('brands'));
    }

    public function testRetargetPointsEachLinkAtTheKeeper(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->method('bound')->willReturn([
            ['relation' => 'public.items', 'column_name' => 'color_id'],
            ['relation' => 'public.other_links', 'column_name' => 'brand_id'],
        ]);
        $source = '01900000-0000-7000-8000-0000000000b1';
        $target = '01900000-0000-7000-8000-0000000000b2';
        $statements->expects($this->exactly(2))->method('change')->willReturnCallback(
            function (string $sql, array $params) use ($source, $target): void {
                self::assertContains($sql, [
                    'UPDATE public.items SET color_id = ?::uuid WHERE color_id = ?::uuid',
                    'UPDATE public.other_links SET brand_id = ?::uuid WHERE brand_id = ?::uuid',
                ]);
                self::assertSame([$target, $source], $params);
            }
        );

        (new CatalogLinks($statements))->retarget('colors', $source, $target);
    }

    /**
     * @param list<array<string, mixed>> $links
     * @param callable(): list<array<string, mixed>> $rows
     */
    private function statements(array $links, callable $rows): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn($links);
        $statements->method('rows')->willReturnCallback($rows);

        return $statements;
    }
}
