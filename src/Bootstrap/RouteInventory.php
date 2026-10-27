<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Routing\Route;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Lists #[Route] attributes in the same order RegisterFeatures registers them.
 */
final class RouteInventory
{
    /**
     * @return list<array{path: string, methods: list<string>, controller: class-string, action: string}>
     */
    public static function routes(string $featureDirectory): array
    {
        $routes = [];
        foreach (RegisterFeatures::controllerClasses($featureDirectory) as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
                foreach ($attributes as $attribute) {
                    $route = $attribute->newInstance();
                    $methods = [];
                    foreach ($route->methods as $httpMethod) {
                        if (!is_string($httpMethod) || $httpMethod === '') {
                            continue;
                        }
                        $methods[] = $httpMethod;
                    }
                    if ($methods === []) {
                        continue;
                    }
                    $routes[] = [
                        'path' => $route->path,
                        'methods' => $methods,
                        'controller' => $class,
                        'action' => $method->getName(),
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * @param list<array{path: string, methods: list<string>, controller: class-string, action: string}> $routes
     * @return array{path: string, methods: list<string>, controller: class-string, action: string}|null
     */
    public static function firstMatch(array $routes, string $path, string $method): ?array
    {
        $method = strtoupper($method);
        foreach ($routes as $route) {
            $methods = [];
            foreach ($route['methods'] as $httpMethod) {
                $methods[] = strtoupper($httpMethod);
            }
            if (!in_array($method, $methods, true)) {
                continue;
            }
            $pattern = '@^' . str_replace('/', '\\/', $route['path']) . '$@';
            if (preg_match($pattern, $path) === 1) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Exact paths with no capture groups (platform and other literal routes).
     *
     * @param list<array{path: string, methods: list<string>, controller: class-string, action: string}> $routes
     * @return list<array{path: string, method: string, controller: class-string, action: string}>
     */
    public static function literalGets(array $routes): array
    {
        $listed = [];
        foreach ($routes as $route) {
            if (str_contains($route['path'], '(')) {
                continue;
            }
            if (!self::allowsMethod($route, 'GET')) {
                continue;
            }
            $listed[] = [
                'path' => $route['path'],
                'method' => 'GET',
                'controller' => $route['controller'],
                'action' => $route['action'],
            ];
        }

        return $listed;
    }

    /**
     * Expand capture groups with sample values so every GET pattern can be requested.
     *
     * @param list<array{path: string, methods: list<string>, controller: class-string, action: string}> $routes
     * @return list<array{path: string, pattern: string, controller: class-string, action: string}>
     */
    public static function sampleGets(array $routes, string $slug): array
    {
        $listed = [];
        foreach ($routes as $route) {
            if (!self::allowsMethod($route, 'GET')) {
                continue;
            }
            $path = self::samplePath($route['path'], $slug);
            if ($path === null) {
                continue;
            }
            $listed[] = [
                'path' => $path,
                'pattern' => $route['path'],
                'controller' => $route['controller'],
                'action' => $route['action'],
            ];
        }

        return $listed;
    }

    /**
     * @param array{path: string, methods: list<string>, controller: class-string, action: string} $route
     */
    private static function allowsMethod(array $route, string $method): bool
    {
        foreach ($route['methods'] as $httpMethod) {
            if (strtoupper($httpMethod) === strtoupper($method)) {
                return true;
            }
        }

        return false;
    }

    private static function samplePath(string $pattern, string $slug): ?string
    {
        $path = $pattern;
        $path = str_replace('([a-z][a-z0-9-]{0,62})', $slug, $path);
        $path = str_replace('([0-9a-fA-F-]{36})', '01900000-0000-7000-8000-000000000001', $path);
        $path = str_replace('([A-Za-z0-9_-]{43})', str_repeat('A', 43), $path);
        $path = str_replace('([0-9a-f]{64})', str_repeat('a', 64), $path);
        $path = str_replace('([1-9][0-9]*)', '1', $path);
        $path = preg_replace(
            '/\(colors\|categories\|sizes\|conditions\|departments\)/',
            'colors',
            $path
        );
        if ($path === null || str_contains($path, '(') || str_contains($path, '|')) {
            return null;
        }

        return $path;
    }
}
