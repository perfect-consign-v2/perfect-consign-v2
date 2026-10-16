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

    /**
     * @param array{id: string, export: string, order?: list<array{0: int, 1: string}>, pageScroll?: bool, fit?: bool} $table
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
        echo '<table id="list-' . View::text($table['id']) . '" class="table list-dt" data-dt-export="'
            . View::text($table['export']) . '"' . $order . $scroll . $fit . '>';
    }

    /**
     * @param list<array{label: string, actions?: bool, sort?: string, token?: string, select?: bool}> $columns
     */
    public static function head(array $columns): void
    {
        echo '<thead><tr>';
        foreach ($columns as $column) {
            $label = $column['label'];
            $actions = ($column['actions'] ?? false) === true;
            $sort = ($column['sort'] ?? '') === 'num' ? 'num' : '';
            $token = ($column['token'] ?? '') === 'id' ? 'id' : '';
            $sortAttr = $sort === '' ? '' : ' data-dt-sort="' . $sort . '"';
            $tokenAttr = $token === '' ? '' : ' data-dt-token="' . $token . '"';
            $cell = $actions
                ? '<th class="list-dt-actions" data-dt-order="disable"' . $sortAttr . $tokenAttr . '>'
                : '<th' . $sortAttr . $tokenAttr . '>';
            $cell .= '<div class="list-dt-th">';
            if ($actions) {
                $cell .= '<span class="list-dt-filter-spacer" aria-hidden="true"></span>';
            } else {
                $caption = $label !== '' ? $label : 'column';
                $cell .= '<input type="search" class="input list-dt-filter" autocomplete="off" aria-label="Filter '
                    . View::text($caption) . '">';
            }
            echo $cell . self::selectBox($column) . '<span class="dt-column-title">' . View::text($label) . '</span></div></th>';
        }
        echo '</tr></thead>';
    }

    /**
     * @param array{label: string, actions?: bool, sort?: string, token?: string, select?: bool} $column
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
            . '<script src="/assets/datatables/dataTables.min.js"></script>'
            . '<script src="/assets/datatables/jszip.min.js"></script>'
            . '<script src="/assets/datatables/pdfmake.min.js"></script>'
            . '<script src="/assets/datatables/vfs_fonts.js"></script>'
            . '<script src="/assets/datatables/dataTables.buttons.min.js"></script>'
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
