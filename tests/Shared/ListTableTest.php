<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Shared\Web\ListTable;
use PHPUnit\Framework\TestCase;

final class ListTableTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['needed', 'printed', 'selectionNeeded', 'selectionPrinted', 'stylePrinted'] as $name) {
            $property = new \ReflectionProperty(ListTable::class, $name);
            $property->setValue(null, false);
        }
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
        self::assertStringNotContainsString('inventory-selection-store.js', $html);
        self::assertStringNotContainsString('data-dt-page-scroll', $html);
        self::assertStringContainsString('/assets/datatables/buttons.colVis.min.js', $html);
        self::assertStringContainsString('/assets/datatables/buttons.html5.min.js', $html);
        self::assertStringContainsString('/assets/datatables/buttons.print.min.js', $html);
        $root = dirname(__DIR__, 2);
        $cssMtime = filemtime($root . '/public/assets/list-datatables.css');
        $jsMtime = filemtime($root . '/public/assets/js/list-datatables.js');
        self::assertIsInt($cssMtime);
        self::assertIsInt($jsMtime);
        self::assertStringContainsString('/assets/list-datatables.css?v=' . $cssMtime, $html);
        self::assertStringContainsString('/assets/js/list-datatables.js?v=' . $jsMtime, $html);
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

    public function testItemsScrollWithThePageAndSelectTheVisibleRows(): void
    {
        ob_start();
        ListTable::open(['id' => 'inventory', 'export' => 'inventory', 'pageScroll' => true]);
        ListTable::head([
            ['label' => 'Print', 'actions' => true, 'select' => true],
        ]);
        ListTable::assets();
        ListTable::assets();
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('id="list-inventory"', $html);
        self::assertStringContainsString('data-dt-page-scroll="1"', $html);
        self::assertStringContainsString('class="list-dt-select-all"', $html);
        self::assertStringContainsString('aria-label="Select all on this page"', $html);
        self::assertSame(1, substr_count($html, 'inventory-selection-store.js'));
        self::assertSame(1, substr_count($html, 'list-datatables.js'));
        $store = strpos($html, 'inventory-selection-store.js');
        $list = strpos($html, 'list-datatables.js');
        self::assertIsInt($store);
        self::assertIsInt($list);
        self::assertLessThan($list, $store);
    }

    public function testSelectionScriptPrintsTheStoreStylesOnce(): void
    {
        ob_start();
        ListTable::selectionScript();
        ListTable::selectionScript();
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertSame(1, substr_count($html, 'inventory-selection-store.js'));
        self::assertSame(1, substr_count($html, 'list-datatables.css'));
        self::assertStringNotContainsString('dataTables.min.js', $html);
    }
}
