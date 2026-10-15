<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Routing\Router;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RegisterFeatures
{
    /**
     * Feature folders also hold page templates. The router loads every PHP file it scans, so only controllers are registered.
     */
    public static function register(Router $router, string $featureDirectory): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($featureDirectory));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->isDir()) {
                continue;
            }
            if (!str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }
            $class = self::className($file->getPathname());
            if ($class === null) {
                continue;
            }
            $router->registerController($class);
        }
    }

    private static function className(string $path): ?string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            return null;
        }
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }
        if (preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        return $namespace[1] . '\\' . $class[1];
    }
}
