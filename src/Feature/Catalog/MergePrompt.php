<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

final class MergePrompt
{
    public static function sentence(int $count, string $source, ?string $keeper): string
    {
        $removed = $source . ' will be removed.';
        if ($count < 1) {
            return $removed;
        }
        $items = $count === 1 ? '1 item' : $count . ' items';
        $shown = $keeper ?? 'the name you choose';

        return $items . ' will show ' . $shown . '. ' . $removed;
    }
}
