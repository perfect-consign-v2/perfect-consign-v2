<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class DirectoryTree
{
    public function remove(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }
        $items = scandir($directory);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if (!$this->removeItem($directory, $item)) {
                return false;
            }
        }

        return rmdir($directory);
    }

    private function removeItem(string $directory, string $item): bool
    {
        if ($item === '.' || $item === '..') {
            return true;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_link($path)) {
            return unlink($path);
        }
        if (is_dir($path)) {
            return $this->remove($path);
        }

        return unlink($path);
    }
}
