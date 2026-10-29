<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Bootstrap\RouteInventory;
use PerfectConsign\Feature\Home\HomeController;
use PerfectConsign\Feature\Root\RootController;
use PerfectConsign\Feature\Signup\SignupController;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class RouteSurfaceTest extends WebTestCase
{
    public function testForgotIsOwnedByRootNotTheMallCatchAll(): void
    {
        RegisterFeatures::clearCache();
        $feature = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $routes = RouteInventory::routes($feature);
        $match = RouteInventory::firstMatch($routes, '/forgot', 'GET');
        self::assertNotNull($match);
        self::assertSame(RootController::class, $match['controller']);
        self::assertSame('forgotForm', $match['action']);
        self::assertNotSame(HomeController::class, $match['controller']);
    }

    public function testSignupIsOwnedBySignupNotTheMallCatchAll(): void
    {
        RegisterFeatures::clearCache();
        $feature = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $routes = RouteInventory::routes($feature);
        $match = RouteInventory::firstMatch($routes, '/signup', 'GET');
        self::assertNotNull($match);
        self::assertSame(SignupController::class, $match['controller']);
        self::assertSame('show', $match['action']);
        self::assertNotSame(HomeController::class, $match['controller']);
    }

    public function testRootControllerRegistersBeforeHomeCatchAll(): void
    {
        RegisterFeatures::clearCache();
        putenv('APP_ENV=test');
        $feature = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $classes = RegisterFeatures::controllerClasses($feature);
        $root = array_search(RootController::class, $classes, true);
        $signup = array_search(SignupController::class, $classes, true);
        $home = array_search(HomeController::class, $classes, true);
        self::assertNotFalse($root);
        self::assertNotFalse($signup);
        self::assertNotFalse($home);
        self::assertLessThan($home, $root);
        self::assertLessThan($home, $signup);
    }

    public function testEveryLiteralGetRouteIsReachable(): void
    {
        RegisterFeatures::clearCache();
        $feature = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $routes = RouteInventory::literalGets(RouteInventory::routes($feature));
        self::assertNotSame([], $routes);
        $paths = [];
        foreach ($routes as $route) {
            $paths[$route['path']] = $route;
        }
        self::assertArrayHasKey('/forgot', $paths);
        self::assertArrayHasKey('/signup', $paths);
        self::assertArrayHasKey('/', $paths);
        self::assertArrayHasKey('/admin/login', $paths);
        self::assertArrayHasKey('/admin/forgot', $paths);
        $client = self::client();
        foreach ($paths as $path => $route) {
            $page = $client->getWithoutRedirect($path);
            self::assertContains(
                $page->status,
                [200, 301, 302, 303],
                $path . ' via ' . $route['controller'] . '::' . $route['action']
                . ' returned ' . (string) $page->status
                . ' (route must not fall through to 404)'
            );
            self::assertStringNotContainsString(
                'This address is not in Perfect Consign.',
                $page->body,
                $path . ' looked like an unknown platform address'
            );
        }
    }

    public function testGlobalForgotPageIsServed(): void
    {
        $page = self::client()->get('/forgot');
        self::assertSame(200, $page->status);
        self::assertSees('Forgot password', $page);
        self::assertSees('name="email"', $page);
    }

    public function testGlobalSignupPageIsServed(): void
    {
        $page = self::client()->get('/signup');
        self::assertSame(200, $page->status);
        self::assertSees('Create your store', $page);
        self::assertStringNotContainsString('Not found', $page->body);
    }

    public function testMallForgotRedirectsToGlobalForgot(): void
    {
        $page = self::client()->getWithoutRedirect('/' . ProbeMall::SLUG . '/forgot');
        self::assertSame(303, $page->status);
        self::assertSame('/forgot', $page->location);
    }

    public function testEveryGetRoutePatternIsMatched(): void
    {
        RegisterFeatures::clearCache();
        $feature = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $samples = RouteInventory::sampleGets(RouteInventory::routes($feature), ProbeMall::SLUG);
        self::assertGreaterThan(50, count($samples));
        $client = self::client();
        $unmatched = [];
        foreach ($samples as $sample) {
            $page = $client->getWithoutRedirect($sample['path']);
            if (str_contains($page->body, 'This address is not in Perfect Consign.')) {
                $unmatched[] = $sample['pattern'] . ' → ' . $sample['path']
                    . ' (' . $sample['controller'] . '::' . $sample['action'] . ')';
            }
        }
        self::assertSame([], $unmatched, "GET patterns fell through to the unknown-route page:\n" . implode("\n", $unmatched));
    }
}
