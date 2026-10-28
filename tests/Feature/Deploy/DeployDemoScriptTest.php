<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Deploy;

use PHPUnit\Framework\TestCase;

final class DeployDemoScriptTest extends TestCase
{
    public function testDeployRebuildsTheFeatureControllerManifest(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        self::assertFileExists($path);
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('composer', $script);
        self::assertStringContainsString('install', $script);
        self::assertMatchesRegularExpression(
            '/composer["\s].*build:routes|build:routes/',
            $script
        );
        $installAt = strpos($script, 'install --no-dev');
        $routesAt = strpos($script, 'build:routes');
        self::assertNotFalse($installAt);
        self::assertNotFalse($routesAt);
        self::assertGreaterThan($installAt, $routesAt);
    }

    public function testDeployDoesNotRequireAPasswordChangeWhenOwnerAlreadyMatches(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('set-owner.php', $script);
        self::assertStringContainsString('idempotent', $script);
    }
}
