<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

/** Turns a server item-list response into the row HTML the page tests already know. */
final class ItemListBody
{
    public static function html(object $client, string $path): string
    {
        $response = $client->get(self::rowsPath($path));
        $body = '';
        if (isset($response->body) && is_string($response->body)) {
            $body = $response->body;
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return $body;
        }

        return self::table($payload['data']);
    }

    public static function rowsPath(string $path): string
    {
        $parts = explode('?', $path, 2);
        $route = $parts[0];
        $query = $parts[1] ?? '';
        if (preg_match('#/vendor/inventory$#', $route) === 1 || preg_match('#/inventory$#', $route) === 1) {
            $route .= '/rows';
        }
        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }
        if (!isset($params['length']) && !isset($params['export'])) {
            $params['length'] = '100';
        }

        return $route . '?' . http_build_query($params);
    }

    /**
     * @param list<mixed> $data
     */
    private static function table(array $data): string
    {
        $html = '';
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = is_string($row['DT_RowId'] ?? null) ? $row['DT_RowId'] : '';
            $class = is_string($row['DT_RowClass'] ?? null) ? $row['DT_RowClass'] : '';
            $cells = '';
            $index = 0;
            while (array_key_exists($index, $row)) {
                $cell = $row[$index];
                $cells .= '<td>' . (is_string($cell) ? $cell : '') . '</td>';
                $index++;
            }
            $open = '<tr id="' . htmlspecialchars($id, ENT_QUOTES) . '"';
            if ($class !== '') {
                $open = '<tr class="' . htmlspecialchars($class, ENT_QUOTES) . '" id="'
                    . htmlspecialchars($id, ENT_QUOTES) . '"';
            }
            $html .= $open . '>' . $cells . '</tr>';
        }

        return $html;
    }
}
