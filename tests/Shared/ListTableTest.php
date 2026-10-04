<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Shared\Web\ListTable;
use PHPUnit\Framework\TestCase;

final class ListTableTest extends TestCase
{
    protected function tearDown(): void
    {
        $needed = new \ReflectionProperty(ListTable::class, 'needed');
        $printed = new \ReflectionProperty(ListTable::class, 'printed');
        $needed->setValue(null, false);
        $printed->setValue(null, false);
    }

    public function testVendorsOpenSortedByCustomIdWithAFilterOnEveryColumn(): void
    {
        ob_start();
        ListTable::open([
            'id' => 'vendors',
            'export' => 'vendors',
            'order' => [[0, 'asc'], [1, 'desc'], [2, 'sideways']],
        ]);
        ListTable::head([
            ['label' => 'Custom ID'],
            ['label' => 'Price', 'sort' => 'num'],
            ['label' => '', 'actions' => true],
        ]);
        ListTable::assets();
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('id="list-vendors"', $html);
        self::assertStringContainsString('data-dt-export="vendors"', $html);
        self::assertStringContainsString('data-dt-order="[[0,&quot;asc&quot;],[1,&quot;desc&quot;]]"', $html);
        self::assertStringNotContainsString('sideways', $html);
        self::assertStringContainsString('aria-label="Filter Custom ID"', $html);
        self::assertStringContainsString('aria-label="Filter Price"', $html);
        self::assertSame(1, substr_count($html, 'data-dt-sort="num"'));
        self::assertMatchesRegularExpression(
            '/<th data-dt-sort="num"><div class="list-dt-th"><input type="search"[^>]*aria-label="Filter Price">/',
            $html
        );
        self::assertStringContainsString('data-dt-order="disable"', $html);
        self::assertStringContainsString('class="dt-column-title">Custom ID</span>', $html);
        self::assertStringContainsString('/assets/js/list-datatables.js', $html);
        self::assertStringContainsString('/assets/datatables/buttons.html5.min.js', $html);
        self::assertStringContainsString('/assets/datatables/buttons.print.min.js', $html);
    }

    public function testIdTokenColumnMarksTheHeader(): void
    {
        ob_start();
        ListTable::open(['id' => 'pay-vendors', 'export' => 'pay-vendors']);
        ListTable::head([
            ['label' => 'Vendor', 'token' => 'id'],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('data-dt-token="id"', $html);
    }

    public function testAssetsStayOffUntilATableOpensAndPrintOnce(): void
    {
        ob_start();
        ListTable::assets();
        $before = ob_get_clean();
        self::assertSame('', $before);

        ob_start();
        ListTable::open(['id' => 'vendors', 'export' => 'vendors']);
        ListTable::assets();
        ListTable::assets();
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertSame(1, substr_count($html, 'list-datatables.js'));
    }
}
