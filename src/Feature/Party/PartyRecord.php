<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Http\View;

final class PartyRecord
{
    /**
     * @param list<array{label: string, value: string, href: string}> $rows
     */
    public static function list(array $rows, string $blank): void
    {
        echo '<dl class="record-dl">';
        foreach ($rows as $row) {
            echo '<div class="record-row"><dt>' . View::text($row['label']) . '</dt><dd>';
            if ($row['value'] === '') {
                echo $blank;
            } elseif ($row['href'] !== '') {
                echo '<a class="' . ($row['label'] === 'Email' ? 'mailto' : '') . '" href="' . View::text($row['href']) . '">' . View::text($row['value']) . '</a>';
            } else {
                echo View::text($row['value']);
            }
            echo '</dd></div>';
        }
        echo '</dl>';
    }
}
