<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PerfectConsign\Feature\Backup\DirectoryTree;
use PHPUnit\Framework\TestCase;

final class DirectoryTreeTest extends TestCase
{
    public function testANestedDirectoryAndALinkAreRemoved(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-tree-' . bin2hex(random_bytes(4));
        $nested = $root . DIRECTORY_SEPARATOR . 'inner';
        if (!mkdir($nested, 0700, true) && !is_dir($nested)) {
            self::fail('could not create a nested directory');
        }
        if (file_put_contents($nested . DIRECTORY_SEPARATOR . 'note.txt', 'x') === false) {
            self::fail('could not write a nested file');
        }
        $link = $root . DIRECTORY_SEPARATOR . 'alias';
        if (PHP_OS_FAMILY !== 'Windows') {
            symlink($nested . DIRECTORY_SEPARATOR . 'note.txt', $link);
        }
        $tree = new DirectoryTree();
        self::assertTrue($tree->remove($root));
        self::assertDirectoryDoesNotExist($root);
        self::assertTrue($tree->remove($root));
    }
}
