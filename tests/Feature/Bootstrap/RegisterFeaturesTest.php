<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectConsign\Bootstrap\RegisterFeatures;
use PHPUnit\Framework\TestCase;

final class RegisterFeaturesTest extends TestCase
{
    private string|false $appEnv = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appEnv = getenv('APP_ENV');
        RegisterFeatures::clearCache();
    }

    protected function tearDown(): void
    {
        RegisterFeatures::clearCache();
        if ($this->appEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->appEnv);
        }
        parent::tearDown();
    }

    public function testControllerScanIsMemoizedInProcess(): void
    {
        RegisterFeatures::clearCache();
        putenv('APP_ENV=test');
        $feature = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $first = RegisterFeatures::controllerClasses($feature);
        self::assertNotSame([], $first);
        self::assertContains('PerfectConsign\\Feature\\Root\\RootController', $first);
        $second = RegisterFeatures::controllerClasses($feature);
        self::assertSame($first, $second);
    }

    public function testBuildManifestWritesAReusableList(): void
    {
        RegisterFeatures::clearCache();
        $feature = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $manifest = RegisterFeatures::manifestPath($feature);
        if (is_file($manifest)) {
            unlink($manifest);
        }
        $built = RegisterFeatures::buildManifest($feature);
        self::assertFileExists($manifest);
        self::assertSame($built, require $manifest);
        RegisterFeatures::clearCache();
        putenv('APP_ENV=production');
        $loaded = RegisterFeatures::controllerClasses($feature);
        self::assertSame($built, $loaded);
        putenv('APP_ENV=test');
        if (is_file($manifest)) {
            unlink($manifest);
        }
    }

    public function testProductionManifestIsReSortedSoRootBeatsHome(): void
    {
        RegisterFeatures::clearCache();
        $feature = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $manifest = RegisterFeatures::manifestPath($feature);
        $directory = dirname($manifest);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            self::fail('could not create cache directory');
        }
        $poison = [
            'PerfectConsign\\Feature\\Home\\HomeController',
            'PerfectConsign\\Feature\\Root\\RootController',
            'PerfectConsign\\Feature\\Admin\\AdminLoginController',
        ];
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($poison, true) . ";\n";
        self::assertNotFalse(file_put_contents($manifest, $payload));
        putenv('APP_ENV=production');
        $loaded = RegisterFeatures::controllerClasses($feature);
        putenv('APP_ENV=test');
        self::assertSame('PerfectConsign\\Feature\\Admin\\AdminLoginController', $loaded[0]);
        self::assertSame('PerfectConsign\\Feature\\Root\\RootController', $loaded[1]);
        self::assertSame('PerfectConsign\\Feature\\Home\\HomeController', $loaded[2]);
        if (is_file($manifest)) {
            unlink($manifest);
        }
    }

    public function testAdminAndRootControllersSortBeforeMallControllers(): void
    {
        RegisterFeatures::clearCache();
        putenv('APP_ENV=test');
        $feature = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        $classes = RegisterFeatures::controllerClasses($feature);
        $firstAdmin = null;
        $firstRoot = null;
        $firstMall = null;
        foreach ($classes as $index => $class) {
            if (str_contains($class, '\\Feature\\Admin\\')) {
                $firstAdmin ??= $index;
                continue;
            }
            if (str_contains($class, '\\Feature\\Root\\')) {
                $firstRoot ??= $index;
                continue;
            }
            $firstMall ??= $index;
        }
        self::assertNotNull($firstAdmin);
        self::assertNotNull($firstRoot);
        self::assertNotNull($firstMall);
        self::assertLessThan($firstRoot, $firstAdmin);
        self::assertLessThan($firstMall, $firstRoot);
    }
}
