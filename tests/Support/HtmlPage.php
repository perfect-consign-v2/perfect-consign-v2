<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use RuntimeException;

final class HtmlPage
{
    public static function csrf(string $html): ?string
    {
        if (preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    public static function inputValue(string $html, string $id): string
    {
        $pattern = '/<input id="' . preg_quote($id, '/') . '"[^>]*value="([^"]*)"/';
        if (preg_match($pattern, $html, $match) !== 1) {
            throw new RuntimeException('input ' . $id . ' was not on the page');
        }

        return $match[1];
    }

    public static function option(string $html, string $selectId, string $label): string
    {
        $select = '/<select id="' . preg_quote($selectId, '/') . '"[^>]*>(.*?)<\/select>/s';
        if (preg_match($select, $html, $block) !== 1) {
            throw new RuntimeException('select ' . $selectId . ' was not on the page');
        }
        $option = '/<option value="([^"]+)">' . preg_quote($label, '/') . '<\/option>/';
        if (preg_match($option, $block[1], $match) !== 1) {
            throw new RuntimeException('option ' . $label . ' was not on the page');
        }

        return $match[1];
    }

    public static function error(string $html): string
    {
        if (preg_match('/<p class="error">([^<]*)<\/p>/', $html, $match) !== 1) {
            return '';
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    public static function covers(string $html, string $date): bool
    {
        $found = preg_match_all(
            '/<td>(\d{4}-\d{2}-\d{2})<\/td>\s*<td>(\d{4}-\d{2}-\d{2})<\/td>/',
            $html,
            $rows,
            PREG_SET_ORDER
        );
        if ($found === false) {
            return false;
        }
        foreach ($rows as $row) {
            if ($row[1] <= $date && $date <= $row[2]) {
                return true;
            }
        }

        return false;
    }
}
