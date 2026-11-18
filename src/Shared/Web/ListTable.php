<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Web;

use PerfectConsign\Shared\Http\View;

final class ListTable
{
    private static bool $needed = false;

    private static bool $printed = false;

    private static bool $selectionNeeded = false;

    private static bool $selectionPrinted = false;

    private static bool $stylePrinted = false;

    private static bool $heavy = false;

    /**
     * @param array{id: string, export: string, order?: list<array{0: int, 1: string}>, pageScroll?: bool, fit?: bool, server?: bool, rows?: string, start?: int, empty?: string} $table
     */
    public static function open(array $table): void
    {
        self::$needed = true;
        $clean = [];
        foreach ($table['order'] ?? [] as $pair) {
            if ($pair[1] !== 'asc' && $pair[1] !== 'desc') {
                continue;
            }
            $clean[] = [$pair[0], $pair[1]];
        }
        $order = $clean === []
            ? ''
            : ' data-dt-order="' . View::text((string) json_encode($clean, JSON_THROW_ON_ERROR)) . '"';
        $scroll = '';
        if (($table['pageScroll'] ?? false) === true) {
            self::$selectionNeeded = true;
            $scroll = ' data-dt-page-scroll="1"';
        }
        $fit = ($table['fit'] ?? false) === true ? ' data-dt-fit="1"' : '';
        $server = self::serverAttrs($table);
        echo '<table id="list-' . View::text($table['id']) . '" class="table list-dt" data-dt-export="'
            . View::text($table['export']) . '"' . $order . $scroll . $fit . $server . '>';
    }

    /**
     * @param array{server?: bool, rows?: string, start?: int, empty?: string} $table
     */
    private static function serverAttrs(array $table): string
    {
        if (($table['server'] ?? false) !== true) {
            self::$heavy = true;

            return '';
        }
        $rows = is_string($table['rows'] ?? null) ? $table['rows'] : '';
        $start = is_int($table['start'] ?? null) ? $table['start'] : 0;
        $empty = is_string($table['empty'] ?? null) ? $table['empty'] : '';
        $emptyAttr = $empty === '' ? '' : ' data-dt-empty="' . View::text($empty) . '"';

        return ' data-dt-server="1" data-dt-rows="' . View::text($rows) . '" data-dt-start="' . $start . '"' . $emptyAttr;
    }

    /**
     * @param list<array{label: string, actions?: bool, sort?: string, token?: string, select?: bool, key?: string, class?: string}> $columns
     */
    public static function head(array $columns): void
    {
        echo '<thead><tr class="list-dt-filters">';
        foreach ($columns as $column) {
            echo self::filterCell($column);
        }
        echo '</tr><tr class="list-dt-titles">';
        foreach ($columns as $column) {
            echo self::titleCell($column);
        }
        echo '</tr></thead>';
    }

    /**
     * @param array{label: string, actions?: bool, sort?: string, token?: string, select?: bool, key?: string, class?: string} $column
     */
    private static function filterCell(array $column): string
    {
        $actions = ($column['actions'] ?? false) === true;
        if ($actions) {
            return '<th class="list-dt-filter-cell" data-dt-order="disable">'
                . '<span class="dt-column-title" hidden></span>'
                . '<span class="list-dt-filter-spacer" aria-hidden="true"></span></th>';
        }
        $label = $column['label'];
        $caption = $label !== '' ? $label : 'column';

        return '<th class="list-dt-filter-cell" data-dt-order="disable"' . self::keyAttr($column) . '>'
            . '<span class="dt-column-title" hidden>' . View::text($caption) . '</span>'
            . '<input type="search" class="input list-dt-filter" autocomplete="off" aria-label="Filter '
            . View::text($caption) . '"></th>';
    }

    /**
     * @param array{label: string, actions?: bool, sort?: string, token?: string, select?: bool, key?: string, class?: string} $column
     */
    private static function titleCell(array $column): string
    {
        $actions = ($column['actions'] ?? false) === true;
        $sort = ($column['sort'] ?? '') === 'num' ? ' data-dt-sort="num"' : '';
        $token = ($column['token'] ?? '') === 'id' ? ' data-dt-token="id"' : '';
        $class = is_string($column['class'] ?? null) && $column['class'] !== ''
            ? ' class="' . View::text($column['class']) . '"'
            : '';
        $attrs = $class . $sort . $token . self::keyAttr($column);
        if ($actions) {
            $attrs = ' class="list-dt-actions" data-dt-order="disable"' . self::keyAttr($column);
        }

        return '<th' . $attrs . '><div class="list-dt-th">' . self::selectBox($column)
            . '<span class="dt-column-title">' . View::text($column['label']) . '</span></div></th>';
    }

    /**
     * @param array{label: string, actions?: bool, sort?: string, token?: string, select?: bool, key?: string, class?: string} $column
     */
    private static function keyAttr(array $column): string
    {
        $key = $column['key'] ?? null;
        if (!is_string($key) || $key === '') {
            return '';
        }

        return ' data-dt-key="' . View::text($key) . '"';
    }

    /**
     * @param array{label: string, actions?: bool, sort?: string, token?: string, select?: bool, key?: string, class?: string} $column
     */
    private static function selectBox(array $column): string
    {
        if (($column['select'] ?? false) === true) {
            return '<input type="checkbox" class="list-dt-select-all" aria-label="Select all on this page">';
        }

        return '';
    }

    public static function selectionScript(): void
    {
        if (self::$selectionPrinted) {
            return;
        }
        self::$selectionPrinted = true;
        self::selectionStyle();
        self::selectionSource();
    }

    public static function assets(): void
    {
        if (!self::$needed || self::$printed) {
            return;
        }
        self::$printed = true;
        $script = dirname(__DIR__, 3) . '/public/assets/js/list-datatables.js';
        echo '<link rel="stylesheet" href="/assets/datatables/dataTables.dataTables.min.css">'
            . '<link rel="stylesheet" href="/assets/datatables/buttons.dataTables.min.css">';
        self::selectionStyle();
        echo '<script src="/assets/js/jquery-3.7.1.min.js"></script>'
            . '<script src="/assets/datatables/dataTables.min.js"></script>';
        if (self::$heavy) {
            echo '<script src="/assets/datatables/jszip.min.js"></script>'
                . '<script src="/assets/datatables/pdfmake.min.js"></script>'
                . '<script src="/assets/datatables/vfs_fonts.js"></script>';
        }
        echo '<script src="/assets/datatables/dataTables.buttons.min.js"></script>'
            . '<script src="/assets/datatables/buttons.colVis.min.js"></script>'
            . '<script src="/assets/datatables/buttons.html5.min.js"></script>'
            . '<script src="/assets/datatables/buttons.print.min.js"></script>';
        if (self::$selectionNeeded && !self::$selectionPrinted) {
            self::$selectionPrinted = true;
            self::selectionSource();
        }
        echo '<script src="/assets/js/list-datatables.js?v=' . View::text(self::assetVersion($script)) . '"></script>';
    }

    private static function selectionStyle(): void
    {
        if (self::$stylePrinted) {
            return;
        }
        self::$stylePrinted = true;
        $path = dirname(__DIR__, 3) . '/public/assets/list-datatables.css';
        echo '<link rel="stylesheet" href="/assets/list-datatables.css?v=' . View::text(self::assetVersion($path)) . '">';
    }

    private static function selectionSource(): void
    {
        $path = dirname(__DIR__, 3) . '/public/assets/js/inventory-selection-store.js';
        echo '<script src="/assets/js/inventory-selection-store.js?v=' . View::text(self::assetVersion($path)) . '"></script>';
    }

    private static function assetVersion(string $path): string
    {
        if (!is_file($path)) {
            return '0';
        }
        $mtime = filemtime($path);
        if (!is_int($mtime)) {
            return '0';
        }

        return (string) $mtime;
    }
}
