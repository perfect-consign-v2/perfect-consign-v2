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
        foreach (self::controllerClasses($featureDirectory) as $class) {
            $router->registerController($class);
        }
    }

    /**
     * @return list<string>
     */
    private static function controllerClasses(string $featureDirectory): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($featureDirectory));
        foreach ($files as $file) {
            $class = self::controllerClass($file);
            if ($class === null) {
                continue;
            }
            $classes[] = $class;
        }
        usort($classes, self::adminRoutesFirst(...));

        return $classes;
    }

    private static function controllerClass(mixed $file): ?string
    {
        if (!$file instanceof SplFileInfo || $file->isDir()) {
            return null;
        }
        if (!str_ends_with($file->getFilename(), 'Controller.php')) {
            return null;
        }

        return self::className($file->getPathname());
    }

    private static function adminRoutesFirst(string $left, string $right): int
    {
        $leftAdmin = str_contains($left, '\\Feature\\Admin\\') ? 0 : 1;
        $rightAdmin = str_contains($right, '\\Feature\\Admin\\') ? 0 : 1;
        if ($leftAdmin !== $rightAdmin) {
            return $leftAdmin <=> $rightAdmin;
        }

        return $left <=> $right;
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
