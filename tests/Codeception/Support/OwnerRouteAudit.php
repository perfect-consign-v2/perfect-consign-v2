<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Bootstrap\RouteInventory;
use PerfectConsign\Feature\Admin\AdminPath;

/**
 * Mall routes an owner can open. A route stays missed until a recorded request matches it.
 */
final class OwnerRouteAudit
{
    /**
     * @param list<array{method: string, path: string}> $hits
     * @return list<string>
     */
    public static function missed(array $hits): array
    {
        $open = [];
        foreach (self::mallRoutes() as $route) {
            foreach ($route['methods'] as $method) {
                $open[] = [
                    'method' => strtoupper($method),
                    'path' => $route['path'],
                ];
            }
        }
        foreach ($hits as $hit) {
            $path = explode('?', $hit['path'], 2)[0];
            $method = strtoupper($hit['method']);
            foreach ($open as $index => $route) {
                if ($route['method'] !== $method) {
                    continue;
                }
                $pattern = '@^' . str_replace('/', '\\/', $route['path']) . '$@';
                if (preg_match($pattern, $path) === 1) {
                    unset($open[$index]);
                }
            }
        }
        $left = [];
        foreach ($open as $route) {
            $left[] = $route['method'] . ' ' . $route['path'];
        }
        sort($left);

        return $left;
    }

    /**
     * @return list<array{path: string, methods: list<string>}>
     */
    private static function mallRoutes(): array
    {
        $root = dirname(__DIR__, 3);
        $feature = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        RegisterFeatures::clearCache();
        $adminPrefix = AdminPath::fromEnv()->prefix();
        $mall = [];
        foreach (RouteInventory::routes($feature) as $route) {
            if (!str_contains($route['path'], '([a-z][a-z0-9-]{0,62})')) {
                continue;
            }
            if (str_starts_with($route['path'], '/admin') || str_starts_with($route['path'], $adminPrefix)) {
                continue;
            }
            $mall[] = [
                'path' => $route['path'],
                'methods' => $route['methods'],
            ];
        }

        return $mall;
    }
}
