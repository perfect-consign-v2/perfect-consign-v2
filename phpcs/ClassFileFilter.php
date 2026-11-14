<?php

declare(strict_types=1);

namespace PerfectConsign\Phpcs;

use PHP_CodeSniffer\Filters\Filter;
use SplFileInfo;

/**
 * PHPCS exclude-pattern matching is case-insensitive, so a pattern cannot
 * tell Actor.php from pos.php. Templates in this repo use a lowercase basename.
 */
final class ClassFileFilter extends Filter
{
    public function accept(): bool
    {
        if (parent::accept() === false) {
            return false;
        }

        $path = $this->currentPath();
        if (is_dir($path)) {
            return true;
        }

        $base = basename($path);

        return $base !== '' && ctype_upper($base[0]);
    }

    private function currentPath(): string
    {
        $current = $this->current();
        if ($current instanceof SplFileInfo) {
            return $current->getPathname();
        }

        return (string) $current;
    }
}
