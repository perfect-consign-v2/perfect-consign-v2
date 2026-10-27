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

    /**
     * Same-mall path+query hrefs from anchors (decoded). Drops empty, hash-only, mailto, tel, admin, external.
     *
     * @return list<string>
     */
    public static function mallHrefs(string $html, string $slug): array
    {
        return self::collectMallHrefs($html, $slug, false);
    }

    /**
     * Nav, button, segment, and Back links only — not every table row (keeps smoke crawl bounded).
     *
     * @return list<string>
     */
    public static function mallSurfaceHrefs(string $html, string $slug): array
    {
        return self::collectMallHrefs($html, $slug, true);
    }

    /**
     * @return list<string>
     */
    private static function collectMallHrefs(string $html, string $slug, bool $surfaceOnly): array
    {
        $found = preg_match_all('/<a\b([^>]*)>/i', $html, $tags, PREG_SET_ORDER);
        if ($found === false || $found < 1) {
            return [];
        }
        $prefix = '/' . $slug;
        $paths = [];
        foreach ($tags as $tag) {
            $attrs = $tag[1];
            if (preg_match('/\bhref="([^"]*)"/i', $attrs, $href) !== 1) {
                continue;
            }
            if ($surfaceOnly && !self::isSurfaceAnchor($attrs)) {
                continue;
            }
            $path = self::mallPath(
                html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5),
                $prefix
            );
            if ($path === null) {
                continue;
            }
            $paths[$path] = $path;
        }

        return array_values($paths);
    }

    private static function isSurfaceAnchor(string $attrs): bool
    {
        return preg_match(
            '/\bclass="[^"]*\b(btn|seg-btn|nav-item|menu-item|text-link)\b/i',
            $attrs
        ) === 1;
    }

    /**
     * Back / empty-href anchors that cannot navigate (JS stubs with href="#" are allowed).
     *
     * @return list<string>
     */
    public static function deadMallAnchors(string $html): array
    {
        $found = preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $tags, PREG_SET_ORDER);
        if ($found === false || $found < 1) {
            return [];
        }
        $dead = [];
        foreach ($tags as $tag) {
            $attrs = $tag[1];
            $inner = strip_tags($tag[2]);
            if (preg_match('/\bhref="([^"]*)"/i', $attrs, $href) !== 1) {
                continue;
            }
            $value = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5);
            $label = trim(html_entity_decode($inner, ENT_QUOTES | ENT_HTML5));
            $isBack = strcasecmp($label, 'Back') === 0
                || preg_match('/\baria-label="[^"]*Back/i', $attrs) === 1;
            if ($value === '') {
                $dead[] = 'empty href (' . ($label !== '' ? $label : 'anchor') . ')';
                continue;
            }
            if ($isBack && ($value === '#' || str_starts_with($value, '#'))) {
                $dead[] = 'Back href="' . $value . '"';
            }
        }

        return $dead;
    }

    /**
     * POST forms under the mall with current field values (csrf omitted for FormClient).
     *
     * @return list<array{action: string, fields: array<string, string>, requiredEmpty: bool}>
     */
    public static function mallForms(string $html, string $slug): array
    {
        $found = preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER);
        if ($found === false || $found < 1) {
            return [];
        }
        $prefix = '/' . $slug;
        $listed = [];
        foreach ($forms as $form) {
            $attrs = $form[1];
            $body = $form[2];
            if (preg_match('/\bmethod="([^"]*)"/i', $attrs, $method) === 1
                && strcasecmp($method[1], 'post') !== 0) {
                continue;
            }
            if (preg_match('/\baction="([^"]*)"/i', $attrs, $actionMatch) !== 1) {
                continue;
            }
            $action = self::mallPath(
                html_entity_decode($actionMatch[1], ENT_QUOTES | ENT_HTML5),
                $prefix
            );
            if ($action === null) {
                continue;
            }
            $fields = self::formFields($body);
            unset($fields['csrf']);
            $listed[] = [
                'action' => $action,
                'fields' => $fields,
                'requiredEmpty' => self::requiredEmpty($body),
            ];
        }

        return $listed;
    }

    public static function isMissPage(string $body): bool
    {
        return str_contains($body, 'This address is not a page in')
            || str_contains($body, 'This address is not in Perfect Consign.');
    }

    private static function mallPath(string $href, string $prefix): ?string
    {
        $href = trim($href);
        if ($href === '' || $href === '#' || str_starts_with($href, '#')
            || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
            || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            $parts = parse_url($href);
            if (!is_array($parts) || !isset($parts['path']) || !is_string($parts['path'])) {
                return null;
            }
            $path = $parts['path'];
            if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
                $path .= '?' . $parts['query'];
            }
            $href = $path;
        }
        if (!str_starts_with($href, '/')) {
            return null;
        }
        if (str_starts_with($href, '/admin')) {
            return null;
        }
        $pathOnly = explode('?', $href, 2)[0];
        if ($pathOnly !== $prefix && !str_starts_with($pathOnly, $prefix . '/')) {
            return null;
        }

        return $href;
    }

    /**
     * @return array<string, string>
     */
    private static function formFields(string $body): array
    {
        $fields = [];
        $inputFound = preg_match_all(
            '/<(input|textarea|select)\b([^>]*)>(?:(.*?)<\/\1>)?/is',
            $body,
            $controls,
            PREG_SET_ORDER
        );
        if ($inputFound === false) {
            return [];
        }
        foreach ($controls as $control) {
            $tag = strtolower($control[1]);
            $attrs = $control[2];
            if (preg_match('/\bname="([^"]+)"/i', $attrs, $name) !== 1) {
                continue;
            }
            $key = html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5);
            if ($tag === 'input') {
                $type = 'text';
                if (preg_match('/\btype="([^"]*)"/i', $attrs, $typeMatch) === 1) {
                    $type = strtolower($typeMatch[1]);
                }
                if (in_array($type, ['submit', 'button', 'image', 'file', 'reset'], true)) {
                    continue;
                }
                if ($type === 'checkbox' || $type === 'radio') {
                    if (preg_match('/\bchecked\b/i', $attrs) !== 1) {
                        continue;
                    }
                }
                $value = '';
                if (preg_match('/\bvalue="([^"]*)"/i', $attrs, $valueMatch) === 1) {
                    $value = html_entity_decode($valueMatch[1], ENT_QUOTES | ENT_HTML5);
                } elseif ($type === 'checkbox') {
                    $value = 'on';
                }
                $fields[$key] = $value;
                continue;
            }
            if ($tag === 'textarea') {
                $fields[$key] = html_entity_decode($control[3] ?? '', ENT_QUOTES | ENT_HTML5);
                continue;
            }
            $selected = null;
            if (preg_match('/<option\b[^>]*\bselected\b[^>]*\bvalue="([^"]*)"/i', $control[0], $opt) === 1) {
                $selected = $opt[1];
            } elseif (preg_match('/<option\b[^>]*\bvalue="([^"]*)"[^>]*\bselected\b/i', $control[0], $opt) === 1) {
                $selected = $opt[1];
            } elseif (preg_match('/<option\b[^>]*\bvalue="([^"]*)"/i', $control[0], $opt) === 1) {
                $selected = $opt[1];
            }
            if ($selected !== null) {
                $fields[$key] = html_entity_decode($selected, ENT_QUOTES | ENT_HTML5);
            }
        }

        return $fields;
    }

    private static function requiredEmpty(string $body): bool
    {
        $found = preg_match_all('/<input\b([^>]*)>/i', $body, $inputs);
        if ($found === false) {
            return false;
        }
        foreach ($inputs[1] as $attrs) {
            if (preg_match('/\brequired\b/i', $attrs) !== 1) {
                continue;
            }
            $type = 'text';
            if (preg_match('/\btype="([^"]*)"/i', $attrs, $typeMatch) === 1) {
                $type = strtolower($typeMatch[1]);
            }
            if (in_array($type, ['hidden', 'checkbox', 'radio', 'submit', 'button'], true)) {
                continue;
            }
            $value = '';
            if (preg_match('/\bvalue="([^"]*)"/i', $attrs, $valueMatch) === 1) {
                $value = $valueMatch[1];
            }
            if (trim($value) === '') {
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
