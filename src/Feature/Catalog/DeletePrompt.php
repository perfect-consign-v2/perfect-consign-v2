<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

final class DeletePrompt
{
    public static function sentence(string $noun): string
    {
        return 'Delete this ' . $noun . '?';
    }
}
