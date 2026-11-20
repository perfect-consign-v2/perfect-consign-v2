<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Container\Container;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Mounts AdminRoute attributes on the private prefix and dispatches them
 * before the mall catch-all. The stock router has no prefix hook.
 */
final class AdminRouter
{
    /** @var list<array{path: string, methods: list<string>, controller: class-string, action: string}> */
    private array $routes = [];

    public function __construct(private readonly AdminPath $path)
    {
    }

    public function register(string $featureDirectory): void
    {
        $this->routes = self::definedRoutes($featureDirectory, $this->path);
    }

    /**
     * @return list<array{path: string, methods: list<string>, controller: class-string, action: string}>
     */
    public static function definedRoutes(string $featureDirectory, AdminPath $path): array
    {
        $routes = [];
        $directory = $featureDirectory . DIRECTORY_SEPARATOR . 'Admin';
        if (!is_dir($directory)) {
            return [];
        }
        $files = scandir($directory);
        if ($files === false) {
            return [];
        }
        foreach ($files as $file) {
            if (!str_ends_with($file, 'Controller.php')) {
                continue;
            }
            $class = 'PerfectConsign\\Feature\\Admin\\' . substr($file, 0, -4);
            if (!class_exists($class)) {
                continue;
            }
            foreach (self::methods($class, $path) as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function dispatch(string $uri, string $method, Container $container): bool
    {
        $method = strtoupper($method);
        foreach ($this->routes as $route) {
            if (!$this->allows($route['methods'], $method)) {
                continue;
            }
            $pattern = '@^' . str_replace('/', '\\/', $route['path']) . '$@';
            if (preg_match($pattern, $uri, $matches) !== 1) {
                continue;
            }
            array_shift($matches);
            $controller = $container->get($route['controller']);
            if (!is_object($controller)) {
                throw new RuntimeException('admin controller was not built');
            }
            $action = [$controller, $route['action']];
            if (!is_callable($action)) {
                throw new RuntimeException('admin controller action is missing');
            }
            $action(...$matches);

            return true;
        }

        return false;
    }

    /**
     * @param class-string $class
     * @return list<array{path: string, methods: list<string>, controller: class-string, action: string}>
     */
    private static function methods(string $class, AdminPath $path): array
    {
        $routes = [];
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(AdminRoute::class, ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $attribute) {
                $route = $attribute->newInstance();
                $methods = self::httpMethods($route->methods);
                if ($methods === []) {
                    continue;
                }
                $routes[] = [
                    'path' => $path->url($route->path),
                    'methods' => $methods,
                    'controller' => $class,
                    'action' => $method->getName(),
                ];
            }
        }

        return $routes;
    }

    /**
     * @param list<string> $methods
     * @return list<string>
     */
    private static function httpMethods(array $methods): array
    {
        $listed = [];
        foreach ($methods as $method) {
            if ($method === '') {
                continue;
            }
            $listed[] = $method;
        }

        return $listed;
    }

    /**
     * @param list<string> $methods
     */
    private function allows(array $methods, string $method): bool
    {
        foreach ($methods as $listed) {
            if (strtoupper($listed) === $method) {
                return true;
            }
        }

        return false;
    }
}
