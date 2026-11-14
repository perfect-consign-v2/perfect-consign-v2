<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class DirectoryTree
{
    private ?bool $asLink = null;

    public function treatAsLink(bool $link): void
    {
        $this->asLink = $link;
    }

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
        if ($this->linked($path)) {
            return unlink($path);
        }
        if (is_dir($path)) {
            return $this->remove($path);
        }

        return unlink($path);
    }

    private function linked(string $path): bool
    {
        if ($this->asLink !== null) {
            $link = $this->asLink;
            $this->asLink = null;

            return $link;
        }

        return is_link($path);
    }
}
