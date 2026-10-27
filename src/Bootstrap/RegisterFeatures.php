<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Routing\Router;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class RegisterFeatures
{
    /** @var array<string, list<string>> */
    private static array $controllers = [];

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
    public static function controllerClasses(string $featureDirectory): array
    {
        if (isset(self::$controllers[$featureDirectory])) {
            return self::$controllers[$featureDirectory];
        }
        $classes = self::fromManifest($featureDirectory);
        if ($classes === null) {
            $classes = self::scan($featureDirectory);
            if (self::useManifest()) {
                self::writeManifest($featureDirectory, $classes);
            }
        }

        return self::$controllers[$featureDirectory] = $classes;
    }

    /**
     * @return list<string>
     */
    public static function buildManifest(string $featureDirectory): array
    {
        $classes = self::scan($featureDirectory);
        if ($classes === []) {
            throw new RuntimeException('feature controller list is empty');
        }
        if (!self::writeManifest($featureDirectory, $classes)) {
            throw new RuntimeException('feature controller manifest could not be written');
        }
        self::$controllers[$featureDirectory] = $classes;

        return $classes;
    }

    public static function clearCache(): void
    {
        self::$controllers = [];
    }

    public static function manifestPath(string $featureDirectory): string
    {
        return dirname($featureDirectory, 2)
            . DIRECTORY_SEPARATOR
            . 'var'
            . DIRECTORY_SEPARATOR
            . 'cache'
            . DIRECTORY_SEPARATOR
            . 'feature-controllers.php';
    }

    /**
     * @return list<string>|null
     */
    private static function fromManifest(string $featureDirectory): ?array
    {
        if (!self::useManifest()) {
            return null;
        }
        $path = self::manifestPath($featureDirectory);
        if (!is_file($path)) {
            return null;
        }
        $loaded = require $path;
        if (!self::validClassList($loaded)) {
            return null;
        }
        // Re-sort every load. A stale unordered manifest must not let Home's
        // /{slug} register before Root's /forgot (demo APP_ENV=production).
        usort($loaded, self::platformRoutesFirst(...));

        return $loaded;
    }

    private static function useManifest(): bool
    {
        $env = getenv('APP_ENV');

        return is_string($env) && $env === 'production';
    }

    /**
     * @return list<string>
     */
    private static function scan(string $featureDirectory): array
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
        usort($classes, self::platformRoutesFirst(...));

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

    /**
     * Exact platform paths (/forgot, /admin/…) must register before the mall
     * catch-all /{slug}, or HomeController steals those names as tenant slugs.
     */
    private static function platformRoutesFirst(string $left, string $right): int
    {
        $leftRank = self::platformRank($left);
        $rightRank = self::platformRank($right);
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        return $left <=> $right;
    }

    private static function platformRank(string $class): int
    {
        if (str_contains($class, '\\Feature\\Admin\\')) {
            return 0;
        }
        if (str_contains($class, '\\Feature\\Root\\')) {
            return 1;
        }

        return 2;
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

    /**
     * @param list<string> $classes
     */
    private static function writeManifest(string $featureDirectory, array $classes): bool
    {
        $path = self::manifestPath($featureDirectory);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        $export = var_export($classes, true);
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $export . ";\n";
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        if (is_file($path) && !unlink($path)) {
            unlink($tmp);

            return false;
        }
        if (rename($tmp, $path)) {
            return true;
        }
        $copied = copy($tmp, $path);
        unlink($tmp);

        return $copied !== false && is_file($path);
    }

    /**
     * @param mixed $loaded
     * @phpstan-assert-if-true list<string> $loaded
     */
    private static function validClassList(mixed $loaded): bool
    {
        if (!is_array($loaded)) {
            return false;
        }
        foreach ($loaded as $class) {
            if (!is_string($class) || $class === '' || !str_contains($class, '\\')) {
                return false;
            }
        }

        return array_is_list($loaded);
    }

}
