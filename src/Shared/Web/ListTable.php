<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Web;

use PerfectConsign\Shared\Http\View;

final class ListTable
{
    private static bool $needed = false;

    private static bool $printed = false;

    /**
     * @param array{id: string, export: string, order?: list<array{0: int, 1: string}>} $table
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
        echo '<table id="list-' . View::text($table['id']) . '" class="table list-dt" data-dt-export="'
            . View::text($table['export']) . '"' . $order . '>';
    }

    /**
     * @param list<array{label: string, actions?: bool, sort?: string, token?: string}> $columns
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
            echo $cell . '<span class="dt-column-title">' . View::text($label) . '</span></div></th>';
        }
        echo '</tr></thead>';
    }

    public static function assets(): void
    {
        if (!self::$needed || self::$printed) {
            return;
        }
        self::$printed = true;
        $path = dirname(__DIR__, 3) . '/public/assets/js/list-datatables.js';
        $version = '0';
        if (is_file($path)) {
            $mtime = filemtime($path);
            if (is_int($mtime)) {
                $version = (string) $mtime;
            }
        }
        echo '<link rel="stylesheet" href="/assets/datatables/dataTables.dataTables.min.css">'
            . '<link rel="stylesheet" href="/assets/datatables/buttons.dataTables.min.css">'
            . '<link rel="stylesheet" href="/assets/list-datatables.css?v=' . View::text($version) . '">'
            . '<script src="/assets/js/jquery-3.7.1.min.js"></script>'
            . '<script src="/assets/datatables/dataTables.min.js"></script>'
            . '<script src="/assets/datatables/jszip.min.js"></script>'
            . '<script src="/assets/datatables/pdfmake.min.js"></script>'
            . '<script src="/assets/datatables/vfs_fonts.js"></script>'
            . '<script src="/assets/datatables/dataTables.buttons.min.js"></script>'
            . '<script src="/assets/datatables/buttons.html5.min.js"></script>'
            . '<script src="/assets/datatables/buttons.print.min.js"></script>'
            . '<script src="/assets/js/list-datatables.js?v=' . View::text($version) . '"></script>';
    }
}
