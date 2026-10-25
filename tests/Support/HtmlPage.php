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

    public static function anchor(string $html, string $elementId): string
    {
        $element = '/id="' . preg_quote($elementId, '/') . '"[^>]*>(.*?)<\/article>/s';
        if (preg_match($element, $html, $block) !== 1) {
            throw new RuntimeException('element ' . $elementId . ' was not on the page');
        }
        if (preg_match('/<a [^>]*href="([^"]+)"/', $block[1], $match) !== 1) {
            throw new RuntimeException('link in ' . $elementId . ' was not on the page');
        }

        return $match[1];
    }

    public static function articleLink(string $html, string $text): string
    {
        $matched = preg_match_all('/<article class="card" id="(?:brand|booth)-[^"]+"[^>]*>.*?<\/article>/s', $html, $articles);
        if ($matched === false || $matched < 1) {
            throw new RuntimeException('brand ' . $text . ' was not on the page');
        }
        foreach ($articles[0] as $article) {
            if (!str_contains($article, $text)) {
                continue;
            }
            if (preg_match('/<a [^>]*href="([^"]+)"/', $article, $match) !== 1) {
                throw new RuntimeException('link for ' . $text . ' was not on the page');
            }

            return $match[1];
        }

        throw new RuntimeException('brand ' . $text . ' was not on the page');
    }

    public static function formAction(string $html, string $elementId): string
    {
        $element = '/id="' . preg_quote($elementId, '/') . '"[^>]*>(.*?)<\/li>/s';
        if (preg_match($element, $html, $block) !== 1) {
            throw new RuntimeException('element ' . $elementId . ' was not on the page');
        }
        if (preg_match('/<form method="post" action="([^"]+)"/', $block[1], $match) !== 1) {
            throw new RuntimeException('form in ' . $elementId . ' was not on the page');
        }

        return $match[1];
    }

    public static function taggedId(string $html, string $tag, string $prefix, string $text): string
    {
        $pattern = '/<' . preg_quote($tag, '/') . '\b[^>]*id="' . preg_quote($prefix, '/') . '-([0-9a-fA-F-]{36})"[^>]*>.*?<\/' . preg_quote($tag, '/') . '>/s';
        $matched = preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
        if ($matched === false || $matched < 1) {
            throw new RuntimeException($prefix . ' ' . $text . ' was not on the page');
        }
        foreach ($matches as $match) {
            if (
                str_contains($match[0], '>' . $text . '<')
                || str_contains($match[0], '>' . $text . '</')
            ) {
                return $match[1];
            }
        }

        throw new RuntimeException($prefix . ' ' . $text . ' was not on the page');
    }

    public static function pathId(string $html, string $pathPrefix): string
    {
        $pattern = '#/' . preg_quote($pathPrefix, '#') . '/([0-9a-fA-F-]{36})#';
        if (preg_match($pattern, $html, $match) !== 1) {
            throw new RuntimeException($pathPrefix . ' id was not on the page');
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
            '/<td>(\d{4}-\d{2}-\d{2}|\d{2}-\d{2}-\d{4})<\/td>\s*<td>(\d{4}-\d{2}-\d{2}|\d{2}-\d{2}-\d{4})<\/td>/',
            $html,
            $rows,
            PREG_SET_ORDER
        );
        if ($found === false) {
            return false;
        }
        $day = self::isoDate($date);
        foreach ($rows as $row) {
            if (self::isoDate($row[1]) <= $day && $day <= self::isoDate($row[2])) {
                return true;
            }
        }

        return false;
    }

    private static function isoDate(string $value): string
    {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $match) === 1) {
            return $match[3] . '-' . $match[1] . '-' . $match[2];
        }

        return $value;
    }
}
