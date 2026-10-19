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
